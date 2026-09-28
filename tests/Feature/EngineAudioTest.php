<?php

use App\Models\Engine;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\Zone;
use App\Services\EngineAudio;
use App\Services\EngineConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
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
    Process::fake(fn () => Process::result(exitCode: 1));
    [$engine, $song] = audioEngine();
    if ($kind === 'format') {
        Storage::disk('local')->put($song->file_path, 'not mp3');
    } else {
        Storage::disk('local')->put('outside.mp3', 'private');
        $song->update(['file_path' => 'outside.mp3']);
    }
    $this->getJson('/api/v1/engine/config')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
})->with(['format', 'path']);

it('converts audio and serves a cached compatible copy while preserving the original', function (string $extension) {
    [$engine, $song] = audioEngine();
    $song->update(['file_path' => 'songs/source.'.$extension]);
    Storage::disk('local')->put($song->file_path, 'source audio');
    $converted = str_repeat("\xff\xfb\x90\x00".str_repeat("\0", 413), 3);
    Process::fake(function (PendingProcess $process) use ($converted) {
        file_put_contents($process->command[array_key_last($process->command)], $converted);

        return Process::result();
    });

    $config = $this->getJson('/api/v1/engine/config')->assertOk()->json('data');
    $audio = $config['zones'][0]['playlist']['songs'][0];
    $this->flushHeaders();
    $response = $this->get($audio['audio_url'])->assertOk()->assertHeader('Content-Type', 'audio/mpeg');

    expect($audio['format']['sample_rate_hz'])->toBe(44100);
    expect($audio['content_version'])->toBe(hash('sha256', $converted));
    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe($converted);
    expect(Storage::disk('local')->get($song->file_path))->toBe('source audio');
    Process::assertRanTimes(fn (PendingProcess $process) => is_array($process->command)
        && in_array('44100', $process->command, true)
        && in_array('libmp3lame', $process->command, true), 1);
})->with(['mp3', 'wav', 'flac', 'aac', 'm4a', 'ogg', 'opus']);

it('keeps compatible MP3 audio unchanged without invoking conversion', function () {
    [$engine, $song] = audioEngine();
    Process::fake();

    $path = app(EngineAudio::class)->path($song);

    expect($path)->toBe(realpath(Storage::disk('local')->path($song->file_path)));
    Process::assertNothingRan();
});

it('rebuilds converted audio when the source changes and denies the old URL', function () {
    [$engine, $song] = audioEngine();
    Storage::disk('local')->put($song->file_path, 'first source');
    $frame = "\xff\xfb\x90\x00".str_repeat("\0", 413);
    $conversion = 0;
    Process::fake(function (PendingProcess $process) use ($frame, &$conversion) {
        file_put_contents($process->command[array_key_last($process->command)], str_repeat($frame, 3 + $conversion++));

        return Process::result();
    });
    $audio = app(EngineAudio::class);
    $first = $audio->metadata($song);
    $url = $audio->authorize($engine, $first)['audio_url'];
    Storage::disk('local')->put($song->file_path, 'second source');

    $second = $audio->metadata($song);

    expect($second['content_version'])->not->toBe($first['content_version']);
    $this->flushHeaders();
    $this->get($url)->assertForbidden();
    Process::assertRanTimes(fn () => true, 2);
});

it('returns 422 and removes partial output when conversion fails or produces invalid audio', function (int $exitCode) {
    [$engine, $song] = audioEngine();
    Storage::disk('local')->put($song->file_path, 'unsupported audio');
    Process::fake(function (PendingProcess $process) use ($exitCode) {
        file_put_contents($process->command[array_key_last($process->command)], 'incomplete');

        return Process::result(exitCode: $exitCode);
    });

    $this->getJson('/api/v1/engine/config')->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');

    expect(glob(Storage::disk('local')->path('engine-audio/convert-*')))->toBe([]);
    expect(glob(Storage::disk('local')->path('engine-audio/*.mp3')))->toBe([]);
    Process::assertRanTimes(fn () => true, 1);
})->with(['failed command' => 1, 'invalid output' => 0]);

it('normalizes real audio at different sample rates with FFmpeg', function (string $extension, int $rate) {
    $binary = getenv('FFMPEG_TEST_BINARY');
    if (! $binary) {
        $this->markTestSkipped('Set FFMPEG_TEST_BINARY to run real audio conversion tests.');
    }
    [$engine, $song] = audioEngine();
    config(['engine.ffmpeg_path' => $binary]);
    $song->update(['file_path' => 'songs/real.'.$extension]);
    $source = Storage::disk('local')->path($song->file_path);
    Process::run([$binary, '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i',
        'sine=frequency=440:sample_rate='.$rate.':duration=0.2', $source])->throw();
    $originalHash = hash_file('sha256', $source);

    $audio = app(EngineAudio::class);
    $metadata = $audio->metadata($song);
    $output = $audio->path($song);

    expect($metadata['format']['sample_rate_hz'])->toBe(44100);
    expect(hash_file('sha256', $source))->toBe($originalHash);
    expect(Process::run([$binary, '-nostdin', '-v', 'error', '-i', $output, '-f', 'null', '-'])->successful())->toBeTrue();
})->with([
    'MP3 8000' => ['mp3', 8000], 'MP3 11025' => ['mp3', 11025],
    'MP3 12000' => ['mp3', 12000], 'MP3 16000' => ['mp3', 16000],
    'MP3 22050' => ['mp3', 22050], 'MP3 24000' => ['mp3', 24000],
    'MP3 32000' => ['mp3', 32000], 'MP3 44100' => ['mp3', 44100],
    'MP3 48000' => ['mp3', 48000], 'WAV 96000' => ['wav', 96000],
    'FLAC 192000' => ['flac', 192000], 'AAC 48000' => ['aac', 48000],
    'M4A 96000' => ['m4a', 96000], 'OGG 48000' => ['ogg', 48000],
]);

it('normalizes a supplied real song without modifying the original', function () {
    $binary = getenv('FFMPEG_TEST_BINARY');
    $fixture = getenv('ENGINE_TEST_SOURCE_AUDIO');
    if (! $binary || ! $fixture) {
        $this->markTestSkipped('Set FFMPEG_TEST_BINARY and ENGINE_TEST_SOURCE_AUDIO for an external audio fixture.');
    }
    [$engine, $song] = audioEngine();
    config(['engine.ffmpeg_path' => $binary]);
    Storage::disk('local')->put($song->file_path, file_get_contents($fixture));
    $originalHash = hash_file('sha256', $fixture);

    $metadata = app(EngineAudio::class)->metadata($song);

    expect($metadata['format']['sample_rate_hz'])->toBe(44100);
    expect($metadata['content_version'])->not->toBe($originalHash);
    expect(hash_file('sha256', $fixture))->toBe($originalHash);
});

it('rejects converted audio larger than the engine limit instead of serving a truncated track', function () {
    [$engine, $song] = audioEngine();
    Storage::disk('local')->put($song->file_path, 'source');
    Process::fake(function (PendingProcess $process) {
        $file = fopen($process->command[array_key_last($process->command)], 'wb');
        ftruncate($file, 32 * 1024 * 1024 + 1);
        fclose($file);

        return Process::result();
    });

    expect(fn () => app(EngineAudio::class)->path($song))->toThrow(ValidationException::class,
        'El audio convertido supera los 32 MiB permitidos por el motor. Use una pista más corta.');

    expect(glob(Storage::disk('local')->path('engine-audio/convert-*')))->toBe([]);
    expect(glob(Storage::disk('local')->path('engine-audio/*.mp3')))->toBe([]);
    Process::assertRanTimes(fn () => true, 1);
});

it('returns a retryable validation message while another request converts the same source', function () {
    [$engine, $song] = audioEngine();
    Storage::disk('local')->put($song->file_path, 'source');
    Storage::disk('local')->makeDirectory('engine-audio');
    $lock = fopen(Storage::disk('local')->path('engine-audio/'.hash('sha256', 'source').'-mp3-v1.mp3.lock'), 'c');
    flock($lock, LOCK_EX);
    Process::fake();

    try {
        expect(fn () => app(EngineAudio::class)->path($song))->toThrow(ValidationException::class,
            'El audio se está preparando. Intente reproducirlo de nuevo en unos segundos.');
        Process::assertNothingRan();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});
