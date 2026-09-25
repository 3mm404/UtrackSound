<?php

namespace App\Http\Middleware;

use App\Models\Engine;
use App\Services\EngineProtocol;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateEngine
{
    public function handle(Request $request, Closure $next): Response
    {
        return DB::transaction(function () use ($request, $next): Response {
            $token = $request->bearerToken();
            $engine = $token ? Engine::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first() : null;
            if (! $engine || ! $engine->enabled) {
                EngineProtocol::reject(401, 'invalid_credential', 'Credencial inválida o equipo deshabilitado.');
            }
            if (! $request->routeIs('engine.sessions') && (! $engine->session_id ||
                ! hash_equals($engine->session_id, (string) $request->header('X-Engine-Session')))) {
                EngineProtocol::reject(409, 'session_superseded', 'La sesión ya no está vigente.');
            }
            $request->attributes->set('engine', $engine);

            return $next($request);
        });
    }
}
