<?php

use App\Events\EngineChanged;
use App\Models\Engine;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\Zone;
use App\Services\EngineConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function connectedEngine(): array
{
    $engine = Engine::factory()->create();
    $token = $engine->issueToken();
    $session = test()->withToken($token)->postJson('/api/v1/engine/sessions', [
        'engine_version' => '0.2.0', 'capabilities' => ['configuration_only'],
    ])->assertCreated()->json('data.session_id');
    test()->withHeader('X-Engine-Session', $session);

    return [$engine->refresh(), $token];
}

function engineReport(array $config, int $sequence = 1): array
{
    return [
        'report_sequence' => $sequence, 'observed_at' => now()->toISOString(),
        'applied_config_revision' => $config['config_revision'], 'config_error' => null,
        'zones' => array_map(fn ($zone) => [
            'zone_id' => $zone['zone_id'], 'state' => 'stopped',
            'playlist_id' => $zone['playlist']['playlist_id'] ?? null, 'song_id' => null,
            'position_ms' => null, 'volume' => $zone['volume'], 'channel_mode' => $zone['channel_mode'],
            'output' => null, 'error' => null,
        ], $config['zones']),
    ];
}

it('rejects missing credentials with 401 and no session', function () {
    $this->postJson('/api/v1/engine/sessions', ['engine_version' => '0.2.0'])
        ->assertUnauthorized()->assertJsonPath('error.code', 'invalid_credential');
});

it('isolates configuration and signs only the authenticated private channel', function () {
    [$engine] = connectedEngine();
    $other = Engine::factory()->create();
    $zone = Zone::create(['business_id' => $engine->business_id, 'engine_id' => $engine->id, 'name' => 'Lobby', 'volume' => 65]);
    Zone::create(['business_id' => $other->business_id, 'engine_id' => $other->id, 'name' => 'Private']);

    $this->getJson('/api/v1/engine/config')->assertOk()
        ->assertJsonCount(1, 'data.zones')->assertJsonPath('data.zones.0.zone_id', (string) $zone->id)
        ->assertJsonPath('data.zones.0.volume', 65);
    config(['broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app', 'broadcasting.connections.reverb.options.host' => '127.0.0.1']);
    $channel = 'private-engines.'.$engine->id;
    $this->postJson('/api/v1/engine/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])
        ->assertOk()->assertJsonPath('auth', 'test-key:'.hash_hmac('sha256', '123.456:'.$channel, 'test-secret'));
    $this->postJson('/api/v1/engine/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-engines.'.$other->id])
        ->assertForbidden()->assertJsonPath('error.code', 'forbidden_channel');
});

it('invalidates an old session and a rotated credential', function () {
    [$engine, $token] = connectedEngine();
    $oldSession = $engine->session_id;
    $this->postJson('/api/v1/engine/sessions', ['engine_version' => '0.2.1', 'capabilities' => ['configuration_only']])->assertCreated();
    $this->withHeader('X-Engine-Session', $oldSession)->getJson('/api/v1/engine/config')
        ->assertConflict()->assertJsonPath('error.code', 'session_superseded');
    $newToken = $engine->issueToken();
    expect($engine->token_hash)->not->toBe($newToken);
    expect($engine->toArray())->not->toHaveKey('token_hash');
    $this->withToken($token)->getJson('/api/v1/engine/config')->assertUnauthorized();
});

it('reports connection from server time and expires after thirty seconds', function () {
    $this->travelTo(now()->startOfSecond());
    [$engine] = connectedEngine();
    $config = $this->getJson('/api/v1/engine/config')->json('data');
    $report = engineReport($config);
    $report['observed_at'] = '2000-01-01T00:00:00Z';
    $this->postJson('/api/v1/engine/heartbeat', $report)->assertOk()->assertJsonPath('data.last_seen_at', now()->toISOString());
    expect($engine->refresh()->isOnline())->toBeTrue();
    $this->travel(31)->seconds();
    expect($engine->refresh()->isOnline())->toBeFalse();
    expect($engine->observed_state['applied_config_revision'])->toBe(1);
});

it('keeps report ordering and rejects conflicting retries with 409', function () {
    [$engine] = connectedEngine();
    $config = $this->getJson('/api/v1/engine/config')->json('data');
    $latest = engineReport($config, 2);
    $this->postJson('/api/v1/engine/heartbeat', $latest)->assertOk();
    $this->postJson('/api/v1/engine/heartbeat', engineReport($config, 1))->assertOk();
    $this->postJson('/api/v1/engine/heartbeat', $latest)->assertOk();
    expect($engine->refresh()->report_sequence)->toBe(2);
    $latest['observed_at'] = '2000-01-01T00:00:00Z';
    $this->postJson('/api/v1/engine/heartbeat', $latest)->assertConflict()->assertJsonPath('error.code', 'report_conflict');
});

it('notifies zone changes and preserves observed state until the next valid report', function () {
    [$engine] = connectedEngine();
    $zone = Zone::create(['business_id' => $engine->business_id, 'engine_id' => $engine->id, 'name' => 'Lobby', 'volume' => 10]);
    $old = $this->getJson('/api/v1/engine/config')->json('data');
    $this->postJson('/api/v1/engine/heartbeat', engineReport($old))->assertOk();
    Event::fake([EngineChanged::class]);
    $playlist = Playlist::create(['business_id' => $engine->business_id, 'name' => 'Jazz']);
    $zone->update(['volume' => 65, 'playlist_id' => $playlist->id]);
    Event::assertDispatched(EngineChanged::class, fn ($event) => $event->deviceId === (string) $engine->id);
    expect($engine->refresh()->observed_state['zones'][0]['volume'])->toBe(10);
    $current = $this->getJson('/api/v1/engine/config')->assertJsonPath('data.zones.0.volume', 65)
        ->assertJsonPath('data.zones.0.playlist.playlist_id', (string) $playlist->id)->json('data');
    expect($current['config_revision'])->toBe(2);
    $this->getJson('/api/v1/engine/config')->assertJsonPath('data.config_revision', 2);
    $this->postJson('/api/v1/engine/heartbeat', engineReport($old, 2))->assertOk();
    $this->postJson('/api/v1/engine/heartbeat', engineReport($current, 3))->assertOk();
    expect($engine->refresh()->observed_state['zones'][0]['volume'])->toBe(65);
});

it('rejects foreign zones and never issued revisions in reports', function () {
    [$engine] = connectedEngine();
    $config = $this->getJson('/api/v1/engine/config')->json('data');
    $report = engineReport($config);
    $report['applied_config_revision'] = 999;
    $this->postJson('/api/v1/engine/heartbeat', $report)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
    expect($engine->refresh()->last_seen_at)->toBeNull();
});

it('persists pending commands until an immutable idempotent result arrives', function () {
    [$engine] = connectedEngine();
    $zone = Zone::create(['business_id' => $engine->business_id, 'engine_id' => $engine->id, 'name' => 'Lobby']);
    $command = app(EngineConfiguration::class)->enqueueStop($engine, (string) $zone->id);
    $this->getJson('/api/v1/engine/commands')->assertJsonCount(1, 'data')->assertJsonPath('data.0.command_id', $command->id);
    $this->getJson('/api/v1/engine/commands')->assertJsonCount(1, 'data');
    $result = ['outcome' => 'succeeded', 'completed_at' => now()->toISOString(), 'error' => null];
    $path = '/api/v1/engine/commands/'.$command->id.'/result';
    $this->putJson($path, $result)->assertOk()->assertJsonPath('data.outcome', 'succeeded');
    $this->putJson($path, $result)->assertOk();
    $this->getJson('/api/v1/engine/commands')->assertJsonCount(0, 'data');
    expect($command->refresh()->result)->toBe($result);
    $result['outcome'] = 'failed';
    $result['error'] = ['code' => 'test', 'message' => 'Different'];
    $this->putJson($path, $result)->assertConflict()->assertJsonPath('error.code', 'result_conflict');
});

it('does not let another device acknowledge a command', function () {
    [$engine] = connectedEngine();
    $zone = Zone::create(['business_id' => $engine->business_id, 'engine_id' => $engine->id, 'name' => 'Lobby']);
    $command = app(EngineConfiguration::class)->enqueueStop($engine, (string) $zone->id);
    connectedEngine();
    $this->putJson('/api/v1/engine/commands/'.$command->id.'/result',
        ['outcome' => 'succeeded', 'completed_at' => now()->toISOString(), 'error' => null])->assertNotFound();
    expect($command->refresh()->result)->toBeNull();
});

it('notifies playlist membership changes and preserves song ordering', function () {
    [$engine] = connectedEngine();
    $playlist = Playlist::create(['business_id' => $engine->business_id, 'name' => 'Jazz']);
    Zone::create(['business_id' => $engine->business_id, 'engine_id' => $engine->id, 'name' => 'Lobby', 'playlist_id' => $playlist->id]);
    $first = Song::create(['title' => 'First', 'file_path' => 'first.mp3']);
    $second = Song::create(['title' => 'Second', 'file_path' => 'second.mp3']);
    Event::fake([EngineChanged::class]);
    $playlist->songs()->attach([$first->id => ['position' => 2], $second->id => ['position' => 1]]);
    Event::assertDispatched(EngineChanged::class);
    $this->getJson('/api/v1/engine/config')->assertJsonPath('data.zones.0.playlist.songs.0.song_id', (string) $second->id);
});
