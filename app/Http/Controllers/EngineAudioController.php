<?php

namespace App\Http\Controllers;

use App\Models\Engine;
use App\Models\Song;
use App\Services\EngineAudio;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EngineAudioController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, Engine $engine, Song $song, EngineAudio $audio): BinaryFileResponse
    {
        abort_unless($request->isSecure() && $request->hasValidSignature(), 403);
        abort_unless($engine->enabled && $engine->token_hash && $engine->session_id &&
            hash_equals($audio->grant($engine), (string) $request->query('grant')), 403);
        abort_unless($engine->zones()->where('business_id', $engine->business_id)
            ->whereHas('playlist', fn ($query) => $query->where('business_id', $engine->business_id)
                ->whereHas('songs', fn ($songs) => $songs->whereKey($song->id)))->exists(), 403);
        $path = $audio->path($song);
        abort_unless(hash_equals(hash_file('sha256', $path), (string) $request->query('version')), 403);

        return response()->file($path, ['Content-Type' => 'audio/mpeg', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff']);
    }
}
