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
    public function snapshot(Engine $engine): array
    {
        return DB::transaction(function () use ($engine): array {
            $engine = Engine::query()->lockForUpdate()->findOrFail($engine->id);
            $zones = $engine->zones()->with('playlist.songs')->orderBy('id')->get()->map(function (Zone $zone): array {
                $playlist = $zone->playlist;
                if ($playlist && $playlist->business_id !== $zone->business_id) {
                    throw ValidationException::withMessages(['playlist_id' => 'La playlist no pertenece al negocio.']);
                }

                return [
                    'zone_id' => (string) $zone->id, 'name' => $zone->name, 'volume' => (int) $zone->volume,
                    'channel_mode' => $zone->channel_mode, 'output' => null,
                    'playlist' => $playlist ? [
                        'playlist_id' => (string) $playlist->id, 'name' => $playlist->name,
                        'songs' => $playlist->songs->map(fn ($song): array => ['song_id' => (string) $song->id])->all(),
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

            return ['device_id' => (string) $engine->id, 'config_revision' => $engine->config_revision,
                'profile' => 'configuration_only', 'zones' => $zones];
        });
    }

    public function enqueueStop(Engine $engine, string $zoneId): EngineCommand
    {
        return DB::transaction(function () use ($engine, $zoneId): EngineCommand {
            $engine = Engine::query()->lockForUpdate()->findOrFail($engine->id);
            if (! $engine->enabled || ! $engine->zones()->whereKey($zoneId)->exists()) {
                throw ValidationException::withMessages(['zone_id' => 'Zona no asignada a un equipo habilitado.']);
            }
            $config = $this->snapshot($engine);
            $engine->refresh();
            $engine->increment('command_sequence');
            $command = $engine->commands()->create([
                'sequence' => $engine->command_sequence, 'zone_id' => $zoneId,
                'config_revision' => $config['config_revision'], 'action' => 'stop',
                'expires_at' => now()->addMinute(),
            ]);
            EngineChanged::dispatch((string) $engine->id);

            return $command;
        });
    }
}
