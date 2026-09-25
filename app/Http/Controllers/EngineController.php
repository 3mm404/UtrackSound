<?php

namespace App\Http\Controllers;

use App\Http\Requests\EngineHeartbeatRequest;
use App\Models\Engine;
use App\Services\EngineConfiguration;
use App\Services\EngineProtocol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EngineController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'engine_version' => ['required', 'string', 'max:100'],
            'capabilities' => ['required', 'array', 'contains:configuration_only'],
            'capabilities.*' => ['string', 'max:100'],
        ]);
        $engine = $request->attributes->get('engine');
        $engine->forceFill([
            'session_id' => (string) Str::uuid(), 'engine_version' => $data['engine_version'],
            'capabilities' => $data['capabilities'], 'report_sequence' => 0, 'last_seen_at' => null,
        ])->save();

        return response()->json(['data' => [
            'device_id' => (string) $engine->id, 'business_id' => (string) $engine->business_id,
            'session_id' => $engine->session_id, 'last_seen_at' => null,
            'heartbeat_interval_seconds' => 10, 'poll_interval_seconds' => config('engine.poll_interval_seconds'),
            'websocket' => [
                'url' => config('engine.websocket_url'),
                'key' => config('broadcasting.connections.reverb.key'),
                'channel' => 'private-engines.'.$engine->id,
            ],
        ]], 201);
    }

    public function configuration(Request $request, EngineConfiguration $configuration): JsonResponse
    {
        return response()->json(['data' => $configuration->snapshot($request->attributes->get('engine'))]);
    }

    public function commands(Request $request): JsonResponse
    {
        $commands = $request->attributes->get('engine')->commands()->whereNull('result')->orderBy('sequence')->limit(100)->get();

        return response()->json(['data' => $commands->map(fn ($command): array => [
            'command_id' => (string) $command->id, 'sequence' => (int) $command->sequence,
            'zone_id' => (string) $command->zone_id, 'config_revision' => (int) $command->config_revision,
            'action' => $command->action, 'created_at' => $command->created_at->toISOString(),
            'expires_at' => $command->expires_at->toISOString(),
        ])]);
    }

    public function result(Request $request, string $commandId): JsonResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:succeeded,failed'],
            'completed_at' => ['required', 'date'],
            'error' => ['present', 'nullable', 'array:code,message', 'required_if:outcome,failed', 'prohibited_if:outcome,succeeded'],
            'error.code' => ['required_with:error', 'string', 'max:100'],
            'error.message' => ['required_with:error', 'string', 'max:1000'],
        ]);
        $command = $request->attributes->get('engine')->commands()->whereKey($commandId)->firstOrFail();
        if ($command->result !== null && EngineProtocol::hash($command->result) !== EngineProtocol::hash($data)) {
            EngineProtocol::reject(409, 'result_conflict', 'El resultado terminal es inmutable.');
        }
        if ($command->result === null) {
            $command->forceFill(['result' => $data])->save();
        }

        return response()->json(['data' => ['command_id' => (string) $command->id, 'outcome' => $data['outcome']]]);
    }

    public function heartbeat(EngineHeartbeatRequest $request): JsonResponse
    {
        $data = $request->validated();
        $engine = $request->attributes->get('engine');
        $report = DB::table('engine_reports')->where('engine_id', $engine->id)
            ->where('session_id', $engine->session_id)->where('sequence', $data['report_sequence'])->first();
        $hash = EngineProtocol::hash($data);
        if ($report && ! hash_equals($report->payload_hash, $hash)) {
            EngineProtocol::reject(409, 'report_conflict', 'La secuencia ya tiene otro reporte.');
        }
        if (! $report) {
            $this->validateReportContext($engine, $data);
            DB::table('engine_reports')->insert([
                'engine_id' => $engine->id, 'session_id' => $engine->session_id,
                'sequence' => $data['report_sequence'], 'payload_hash' => $hash,
            ]);
        }
        if ($data['report_sequence'] > $engine->report_sequence) {
            $engine->forceFill(['report_sequence' => $data['report_sequence'], 'observed_state' => $data,
                'last_seen_at' => now()])->save();
        }

        return response()->json(['data' => ['last_seen_at' => $engine->last_seen_at?->toISOString()]]);
    }

    private function validateReportContext(Engine $engine, array $data): void
    {
        $revision = (int) $data['applied_config_revision'];
        $snapshot = $revision === 0 ? [] : DB::table('engine_configurations')
            ->where('engine_id', $engine->id)->where('revision', $revision)->value('snapshot');
        if ($snapshot === null) {
            throw ValidationException::withMessages(['applied_config_revision' => 'Revisión no emitida para este equipo.']);
        }
        $zones = collect(is_string($snapshot) ? json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR) : $snapshot)->keyBy('zone_id');
        if ($zones->count() !== count($data['zones'])) {
            throw ValidationException::withMessages(['zones' => 'El reporte debe incluir todas las zonas de la revisión aplicada.']);
        }
        foreach ($data['zones'] as $reported) {
            $zone = $zones->get($reported['zone_id']);
            if (! $zone || $reported['volume'] !== $zone['volume'] ||
                $reported['channel_mode'] !== $zone['channel_mode'] ||
                $reported['playlist_id'] !== ($zone['playlist']['playlist_id'] ?? null)) {
                throw ValidationException::withMessages(['zones' => 'Estado ajeno a la configuración aplicada.']);
            }
        }
    }

    public function broadcastAuth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'socket_id' => ['required', 'string', 'regex:/\\A[0-9]+\\.[0-9]+\\z/'],
            'channel_name' => ['required', 'string', 'max:200'],
        ]);
        if ($data['channel_name'] !== 'private-engines.'.$request->attributes->get('engine')->id) {
            EngineProtocol::reject(403, 'forbidden_channel', 'Canal no autorizado.');
        }

        return response()->json(Broadcast::connection('reverb')->validAuthenticationResponse($request, true));
    }
}
