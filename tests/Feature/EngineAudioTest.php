<?php

use App\Models\Engine;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\Zone;
use App\Services\EngineAudio;
use App\Services\EngineConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('delivers a specific song in the command API', function () {
    [$engine, $song, $zone] = audioEngine();
    $engine->forceFill(['engine_version' => '0.6.0'])->save();
    app(EngineConfiguration::class)->enqueue($engine, (string) $zone->id, 'play', (string) $song->id);
    $this->getJson('/api/v1/engine/commands')->assertOk()->assertJsonPath('data.0.song_id', (string) $song->id);
});

function audioEngine(string $profile = EngineAudio::PROFILE): array
{
    config(['engine.media_url' => 'https://localhost']);
    Storage::fake('local');
    $engine = Engine::factory()->create();
    $token = $engine->issueToken();
    $session = test()->withToken($token)->postJson('/api/v1/engine/sessions', [
        'engine_version' => '0.5.0', 'capabilities' => [$profile],
    ])->assertCreated()->json('data.session_id');
    test()->withHeader('X-Engine-Session', $session);
    $playlist = Playlist::create(['business_id' => $engine->business_id, 'name' => 'Audio']);
    $song = Song::create(['title' => 'MP3', 'file_path' => 'songs/test.mp3']);
    Storage::disk('local')->put($song->file_path, str_repeat("\xff\xfb\x90\x00".str_repeat("\0", 413), 3));
    $playlist->songs()->attach($song, ['position' => 1]);
    $zone = Zone::create(['business_id' => $engine->business_id, 'engine_id' => $engine->id,
        'playlist_id' => $playlist->id, 'name' => 'A', 'channel_mode' => 'stereo']);

    return [$engine->refresh(), $song, $zone->refresh()];
}

it('delivers private audio with a signed HTTPS URL without bearer credentials', function () {
    [$engine, $song] = audioEngine();
    $config = $this->getJson('/api/v1/engine/config')->assertOk()->json('data');
    $audio = $config['zones'][0]['playlist']['songs'][0];
    expect($audio['audio_url'])->toStartWith('https://localhost/api/v1/engine/audio/');
    expect($audio['format']['sample_rate_hz'])->toBe(44100);
    expect($audio['content_version'])->toBe(hash('sha256', Storage::disk('local')->get($song->file_path)));
    $this->flushHeaders();
    $response = $this->get($audio['audio_url'])->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    expect($response->baseResponse->getFile()->getPathname())->toBe(realpath(Storage::disk('local')->path($song->file_path)));
    $this->get(preg_replace('/&signature=[^&]+/', '', $audio['audio_url']))->assertForbidden();
});

it('renews expired URLs without changing revision and invalidates replaced content', function () {
    $this->freezeTime();
    [$engine, $song] = audioEngine();
    $service = app(EngineConfiguration::class);
    $first = $service->snapshot($engine);
    $url = $first['zones'][0]['playlist']['songs'][0]['audio_url'];
    $this->travel(301)->seconds();
    $this->get($url)->assertForbidden();
    $second = $service->snapshot($engine);
    expect($second['config_revision'])->toBe($first['config_revision']);
    $renewed = $second['zones'][0]['playlist']['songs'][0]['audio_url'];
    expect($renewed)->not->toBe($url);
    $this->get($renewed)->assertOk();
    Storage::disk('local')->append($song->file_path, 'new bytes');
    $this->get($renewed)->assertForbidden();
    expect($service->snapshot($engine)['config_revision'])->toBe($first['config_revision'] + 1);
});

it('revokes signed audio when the engine or assignment is no longer authorized', function (string $change) {
    [$engine, $song, $zone] = audioEngine();
    $config = app(EngineConfiguration::class)->snapshot($engine);
    $url = $config['zones'][0]['playlist']['songs'][0]['audio_url'];
    match ($change) {
        'disabled' => $engine->update(['enabled' => false]),
        'rotated' => $engine->issueToken(),
        'removed' => $zone->update(['playlist_id' => null]),
        'session' => $engine->forceFill(['session_id' => 'new-session'])->save(),
    };
    $this->flushHeaders();
    $this->get($url)->assertForbidden();
})->with(['disabled', 'rotated', 'removed', 'session']);

it('denies audio assigned to a different engine even with a server signature', function () {
    [$engine, $song] = audioEngine();
    $other = Engine::factory()->create();
    $other->issueToken();
    $other->forceFill(['session_id' => 'other'])->save();
    $audio = app(EngineAudio::class);
    $url = $audio->authorize($other, $audio->metadata($song))['audio_url'];
    $this->flushHeaders();
    $this->get($url)->assertForbidden();
});

it('accepts actual playback reports and rejects a foreign song or output', function (string $profile) {
    [$engine, $song, $zone] = audioEngine($profile);
    $config = $this->getJson('/api/v1/engine/config')->assertOk()->json('data');
    $mode = $profile === EngineAudio::MONO_PROFILE ? 'mono' : 'stereo';
    expect($config['zones'][0]['channel_mode'])->toBe($mode);
    $report = ['report_sequence' => 1, 'observed_at' => now()->toISOString(),
        'applied_config_revision' => $config['config_revision'], 'config_error' => null,
        'zones' => [['zone_id' => (string) $zone->id, 'state' => 'playing',
            'playlist_id' => (string) $zone->playlist_id, 'song_id' => (string) $song->id,
            'position_ms' => null, 'volume' => $zone->volume, 'channel_mode' => $mode,
            'output' => ['device_id' => 'default', 'channels' => $mode === 'mono' ? [1] : [1, 2]], 'error' => null]]];
    $this->postJson('/api/v1/engine/heartbeat', $report)->assertOk();
    expect($engine->refresh()->observed_state['zones'][0]['state'])->toBe('playing');
    $report['report_sequence']++;
    $report['zones'][0]['song_id'] = 'foreign';
    $this->postJson('/api/v1/engine/heartbeat', $report)->assertUnprocessable();
    $report['zones'][0]['song_id'] = (string) $song->id;
    $report['zones'][0]['output']['channels'] = [2, 1];
    $this->postJson('/api/v1/engine/heartbeat', $report)->assertUnprocessable();
})->with([EngineAudio::PROFILE, EngineAudio::MONO_PROFILE]);

it('assigns exclusive channels to three zones and accepts their playback reports', function (string $profile, array $channels) {
    [$engine, $song, $first] = audioEngine($profile);
    foreach (['B', 'C'] as $name) {
        Zone::create(['business_id' => $engine->business_id, 'engine_id' => $engine->id,
            'playlist_id' => $first->playlist_id, 'name' => $name, 'channel_mode' => 'stereo']);
    }

    $config = $this->getJson('/api/v1/engine/config')->assertOk()->json('data');

    expect(array_column(array_column($config['zones'], 'output'), 'channels'))->toBe($channels);
    $report = ['report_sequence' => 1, 'observed_at' => now()->toISOString(),
        'applied_config_revision' => $config['config_revision'], 'config_error' => null,
        'zones' => array_map(fn (array $zone): array => [
            'zone_id' => $zone['zone_id'], 'state' => 'playing',
            'playlist_id' => $zone['playlist']['playlist_id'], 'song_id' => (string) $song->id,
            'position_ms' => null, 'volume' => $zone['volume'], 'channel_mode' => $zone['channel_mode'],
            'output' => $zone['output'], 'error' => null,
        ], $config['zones'])];
    $this->postJson('/api/v1/engine/heartbeat', $report)->assertOk();
    expect($engine->refresh()->observed_state['zones'])->toBe($report['zones']);
})->with([
    'stereo pairs' => [EngineAudio::PROFILE, [[1, 2], [3, 4], [5, 6]]],
    'mono channels' => [EngineAudio::MONO_PROFILE, [[1], [2], [3]]],
]);

it('queues each supported playback action and rejects foreign zones', function (string $action) {
    [$engine, $song, $zone] = audioEngine();
    $command = app(EngineConfiguration::class)->enqueue($engine, (string) $zone->id, $action);
    $this->getJson('/api/v1/engine/commands')->assertOk()->assertJsonPath('data.0.action', $action);
    $this->assertDatabaseHas('engine_commands', ['id' => $command->id, 'action' => $action, 'result' => null]);
    expect(fn () => app(EngineConfiguration::class)->enqueue($engine, '999999', $action))
        ->toThrow(ValidationException::class);
})->with(['play', 'pause', 'resume', 'stop', 'next', 'previous']);

it('imports public audio into private storage without overwriting different content', function () {
    Storage::fake('public');
    Storage::fake('local');
    $song = Song::create(['title' => 'Old', 'file_path' => 'songs/old.mp3']);
    Storage::disk('public')->put($song->file_path, 'original');
    Storage::disk('local')->put($song->file_path, 'different');
    $this->artisan('engine:import-audio')->assertFailed();
    Storage::disk('public')->assertExists($song->file_path);
    expect(Storage::disk('local')->get($song->file_path))->toBe('different');
    Storage::disk('local')->delete($song->file_path);
    $this->artisan('engine:import-audio')->assertSuccessful();
    Storage::disk('public')->assertMissing($song->file_path);
    expect(Storage::disk('local')->get($song->file_path))->toBe('original');
});

it('rejects unsupported media and paths outside private songs', function (string $kind) {
    [$engine, $song] = audioEngine();
    if ($kind === 'format') {
        Storage::disk('local')->put($song->file_path, 'not mp3');
    } else {
        Storage::disk('local')->put('outside.mp3', 'private');
        $song->update(['file_path' => 'outside.mp3']);
    }
    $this->getJson('/api/v1/engine/config')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
})->with(['format', 'path']);
