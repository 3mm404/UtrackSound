<?php

use App\Models\Engine;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Zone;
use App\Services\EngineConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

it('synchronizes through real Laravel Reverb and Go and recovers pending work after reconnection', function () {
    $binary = getenv('ENGINE_E2E_BINARY');
    expect(is_file($binary))->toBeTrue('Compile cmd/agent and set ENGINE_E2E_BINARY to its absolute path.');
    $root = storage_path('framework/testing');
    File::ensureDirectoryExists($root);
    $directory = $root.'/engine-e2e-'.Str::uuid();
    File::ensureDirectoryExists($directory);
    $database = $directory.'/database.sqlite';
    touch($database);
    $port = function (): int {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException($errorMessage, $errorCode);
        }
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    };
    $httpPort = $port();
    $wsPort = $port();
    $url = 'http://127.0.0.1:'.$httpPort;
    $environment = [
        'APP_ENV' => getenv('ENGINE_E2E_BROWSER_REVIEW') ? 'local' : 'testing',
        'APP_DEBUG' => 'false', 'APP_URL' => $url,
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'file',
        'BROADCAST_CONNECTION' => 'reverb', 'QUEUE_CONNECTION' => 'database',
        'REVERB_APP_ID' => 'engine-e2e', 'REVERB_APP_KEY' => 'engine-e2e-key',
        'REVERB_APP_SECRET' => Str::random(32), 'REVERB_HOST' => '127.0.0.1',
        'REVERB_PORT' => (string) $wsPort, 'REVERB_SCHEME' => 'http',
        'ENGINE_WEBSOCKET_URL' => 'ws://127.0.0.1:'.$wsPort,
        // No fallback poll can hide a failed subscription or missed notification.
        'ENGINE_POLL_INTERVAL_SECONDS' => '300',
    ];
    $processes = [];
    $start = function (array $command, array $extra = [], ?string $workingDirectory = null) use (&$processes, $environment): Process {
        $process = new Process($command, $workingDirectory ?? base_path(), array_merge($environment, $extra), timeout: null);
        $process->start();
        $processes[] = $process;

        return $process;
    };
    $waitUntil = function (Closure $condition, string $message, float $timeout = 15) use (&$processes): void {
        $deadline = microtime(true) + $timeout;
        do {
            if ($condition()) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        $statuses = array_map(fn (Process $process): string => $process->isRunning() ? 'running' : 'exited '.$process->getExitCode(), $processes);
        $agentOutput = $processes ? end($processes)->getErrorOutput() : '';
        $httpOutput = $processes ? $processes[0]->getErrorOutput() : '';
        throw new RuntimeException($message.'; processes: '.implode(', ', $statuses).'; agent: '.$agentOutput.'; http: '.substr($httpOutput, -2000));
    };
    $canConnect = function (int $port): bool {
        $socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errorCode, $errorMessage, 0.1);
        if ($socket !== false) {
            fclose($socket);

            return true;
        }

        return false;
    };
    $oldDefault = config('database.default');
    try {
        config([
            'database.default' => 'engine_e2e',
            'database.connections.engine_e2e' => array_merge(config('database.connections.sqlite'), [
                'url' => null, 'database' => $database, 'busy_timeout' => 5000,
            ]),
            'queue.default' => 'database',
            'broadcasting.default' => 'reverb',
        ]);
        expect(Artisan::call('migrate', ['--database' => 'engine_e2e', '--force' => true]))->toBe(0);
        $engine = Engine::factory()->create(['server_url' => $url]);
        $playlist = Playlist::create(['business_id' => $engine->business_id, 'name' => 'Initial']);
        $zone = Zone::create(['business_id' => $engine->business_id, 'engine_id' => $engine->id,
            'name' => 'Integration lobby', 'volume' => 15, 'playlist_id' => $playlist->id]);
        $token = $engine->issueToken();

        $http = $start([PHP_BINARY, '-S', '127.0.0.1:'.$httpPort, '-t', public_path(),
            base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], workingDirectory: public_path());
        $reverbCommand = [PHP_BINARY, 'artisan', 'reverb:start', '--host=127.0.0.1', '--port='.$wsPort, '--debug', '--no-interaction'];
        $reverb = $start($reverbCommand);
        $worker = $start([PHP_BINARY, 'artisan', 'queue:work', '--sleep=1', '--tries=5', '--backoff=1', '--no-interaction']);
        $waitUntil(fn (): bool => $canConnect($httpPort) && $canConnect($wsPort), 'Laravel / Reverb did not start');
        $agent = $start([$binary], ['ENGINE_SERVER' => $url, 'ENGINE_TOKEN' => $token, 'ENGINE_STATE_DIR' => $directory.'/state']);
        $waitUntil(fn (): bool => ($engine->refresh()->observed_state['zones'][0]['volume'] ?? null) === 15, 'Initial config was not applied');
        $waitUntil(fn (): bool => str_contains($reverb->getOutput(), 'pusher:subscribe'), 'Agent did not subscribe to the private Reverb channel');
        expect($engine->isOnline())->toBeTrue();
        expect($engine->engine_version)->toBe('0.2.0');

        $newPlaylist = Playlist::create(['business_id' => $engine->business_id, 'name' => 'Updated']);
        $zone->update(['volume' => 65, 'playlist_id' => $newPlaylist->id]);
        $waitUntil(fn (): bool => ($engine->refresh()->observed_state['zones'][0]['volume'] ?? null) === 65, 'WebSocket notification did not apply changed config');
        expect($engine->observed_state['zones'][0]['playlist_id'])->toBe((string) $newPlaylist->id);
        expect($engine->observed_state['applied_config_revision'])->toBe($engine->config_revision);

        $reverb->stop(1);
        $worker->stop(1);
        $zone->update(['volume' => 35]);
        $command = app(EngineConfiguration::class)->enqueueStop($engine, (string) $zone->id);
        expect($command->result)->toBeNull();
        expect($engine->refresh()->observed_state['zones'][0]['volume'])->toBe(65);
        $reverb = $start($reverbCommand);
        $waitUntil(fn (): bool => ($engine->refresh()->observed_state['zones'][0]['volume'] ?? null) === 35, 'Reconnect did not recover the offline configuration');
        $waitUntil(fn (): bool => $command->refresh()->result !== null, 'Reconnect did not acknowledge the pending command');
        expect($command->result['outcome'])->toBe('succeeded');
        expect(str_contains($reverb->getOutput(), 'pusher:subscribe'))->toBeTrue();
        expect($http->isRunning())->toBeTrue();

        // Simulate a lost server acknowledgement after the engine persisted its result.
        $originalResult = $command->result;
        $command->forceFill(['result' => null])->save();
        $agent->stop(1);
        $agent = $start([$binary], ['ENGINE_SERVER' => $url, 'ENGINE_TOKEN' => $token, 'ENGINE_STATE_DIR' => $directory.'/state']);
        $waitUntil(fn (): bool => $command->refresh()->result !== null, 'Restart did not resend the durable command result');
        expect($command->result)->toBe($originalResult);

        if (getenv('ENGINE_E2E_BROWSER_REVIEW')) {
            $reviewer = User::factory()->create(['name' => 'Revisión de integración', 'email' => 'review@example.test', 'password' => 'Engine-review-only-2026']);
            $reviewer->forceFill(['is_super_admin' => true])->save();
            foreach (['ViewAny:Zone', 'View:Zone', 'Update:Zone'] as $permission) {
                Permission::findOrCreate($permission, 'web');
                $reviewer->givePermissionTo($permission);
            }
            File::put($root.'/engine-browser-review.json', json_encode([
                'url' => $url.'/admin', 'complete_file' => $directory.'/browser-reviewed',
            ], JSON_THROW_ON_ERROR));
            $waitUntil(fn (): bool => is_file($directory.'/browser-reviewed'), 'Browser review did not finish', 300);
            File::delete($root.'/engine-browser-review.json');
        }

        $agent->stop(1);
        $waitUntil(fn (): bool => ! $engine->refresh()->isOnline(), 'Engine remained online without heartbeats', 35);
        expect($engine->observed_state['zones'][0]['volume'])->toBe(35);
    } finally {
        foreach (array_reverse($processes) as $process) {
            $process->stop(1);
        }
        DB::disconnect('engine_e2e');
        DB::purge('engine_e2e');
        config(['database.default' => $oldDefault]);
        $resolved = realpath($directory);
        $resolvedRoot = realpath($root);
        if ($resolved !== false && $resolvedRoot !== false && str_starts_with($resolved, $resolvedRoot.DIRECTORY_SEPARATOR.'engine-e2e-')) {
            File::deleteDirectory($resolved);
        }
    }
})->skip(! getenv('ENGINE_E2E_BINARY'), 'Set ENGINE_E2E_BINARY to run the real multi-process integration.')->group('engine-integration');
