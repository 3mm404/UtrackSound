<?php

namespace App\Services;

use App\Events\EngineChanged;
use App\Models\Engine;
use App\Models\EngineCommand;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EngineConfiguration
{
    public function __construct(private EngineAudio $audio) {}

    public function snapshot(Engine $engine): array
    {
        return DB::transaction(function () use ($engine): array {
            $engine = Engine::query()->lockForUpdate()->findOrFail($engine->id);
            $profile = $this->audio->profile($engine);
            $playback = $profile !== 'configuration_only';
            $metadata = [];
            $zones = $engine->zones()->with('playlist.songs')->orderBy('id')->get()->map(function (Zone $zone) use ($playback, $profile, &$metadata): array {
                $playlist = $zone->playlist;
                if ($playlist && $playlist->business_id !== $zone->business_id) {
                    throw ValidationException::withMessages(['playlist_id' => 'La playlist no pertenece al negocio.']);
                }

                return [
                    'zone_id' => (string) $zone->id, 'name' => $zone->name, 'volume' => (int) $zone->volume,
                    'channel_mode' => $playback ? ($profile === EngineAudio::MONO_PROFILE ? 'mono' : 'stereo') : $zone->channel_mode,
                    'output' => $playback ? ['device_id' => 'default', 'channels' => [1, 2]] : null,
                    'playlist' => $playlist ? [
                        'playlist_id' => (string) $playlist->id, 'name' => $playlist->name,
                        'songs' => $playlist->songs->map(function ($song) use ($playback, &$metadata): array {
                            return $playback ? ($metadata[$song->id] ??= $this->audio->metadata($song)) : ['song_id' => (string) $song->id];
                        })->all(),
                    ] : null,
                ];
            })->all();
            $hash = EngineProtocol::hash($zones);
            if ($engine->config_hash !== $hash) {
                $engine->forceFill(['config_hash' => $hash, 'config_revision' => $engine->config_revision + 1])->save();
                DB::table('engine_configurations')->insert([
                    'engine_id' => $engine->id, 'revision' => $engine->config_revision,
                    'snapshot' => json_encode($zones, JSON_THROW_ON_ERROR),
                ]);
            }

            if ($playback) {
                foreach ($zones as &$zone) {
                    if ($zone['playlist'] !== null) {
                        foreach ($zone['playlist']['songs'] as &$song) {
                            $song = $this->audio->authorize($engine, $song);
                        }
                        unset($song);
                    }
                }
                unset($zone);
            }

            return ['device_id' => (string) $engine->id, 'config_revision' => $engine->config_revision,
                'profile' => $profile, 'zones' => $zones];
        });
    }

    public function enqueueStop(Engine $engine, string $zoneId): EngineCommand
    {
        return $this->enqueue($engine, $zoneId, 'stop');
    }

    public function enqueue(Engine $engine, string $zoneId, string $action, ?string $songId = null): EngineCommand
    {
        if (! in_array($action, ['play', 'pause', 'resume', 'stop', 'next', 'previous'], true)) {
            throw ValidationException::withMessages(['action' => 'Acción no permitida.']);
        }

        if ($songId !== null && $action !== 'play') {
            throw ValidationException::withMessages(['song_id' => 'Solo Reproducir admite una canción específica.']);
        }

        return DB::transaction(function () use ($engine, $zoneId, $action, $songId): EngineCommand {
            $engine = Engine::query()->lockForUpdate()->findOrFail($engine->id);
            if (! $engine->enabled || ! $engine->zones()->whereKey($zoneId)->exists()) {
                throw ValidationException::withMessages(['zone_id' => 'Zona no asignada a un equipo habilitado.']);
            }
            $config = $this->snapshot($engine);
            if ($action !== 'stop' && $config['profile'] === 'configuration_only') {
                throw ValidationException::withMessages(['action' => 'El equipo no admite reproducción.']);
            }
            if ($songId !== null) {
                if (version_compare($engine->engine_version ?? '0.0.0', '0.6.0', '<')) {
                    throw ValidationException::withMessages(['song_id' => 'Actualiza el engine a 0.6.0 para elegir una canción.']);
                }
                $zone = collect($config['zones'])->firstWhere('zone_id', $zoneId);
                if (! in_array($songId, array_column($zone['playlist']['songs'] ?? [], 'song_id'), true)) {
                    throw ValidationException::withMessages(['song_id' => 'La canción no pertenece a la playlist actual de esta zona.']);
                }
            }
            $engine->refresh();
            $engine->increment('command_sequence');
            $command = $engine->commands()->create([
                'sequence' => $engine->command_sequence, 'zone_id' => $zoneId,
                'config_revision' => $config['config_revision'], 'action' => $action, 'song_id' => $songId,
                'expires_at' => now()->addMinute(),
            ]);
            EngineChanged::dispatch((string) $engine->id);

            return $command;
        });
    }
}
