<?php

namespace App\Services;

use App\Models\Engine;
use App\Models\Song;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class EngineAudio
{
    public const PROFILE = 'shared_stereo_mp3';

    public const MONO_PROFILE = 'shared_mono_mp3';

    public function profile(Engine $engine): string
    {
        foreach ([self::MONO_PROFILE, self::PROFILE] as $profile) {
            if (in_array($profile, $engine->capabilities ?? [], true)) {
                return $profile;
            }
        }

        return 'configuration_only';
    }

    public function path(Song $song): string
    {
        $root = realpath(Storage::disk('local')->path('songs'));
        $path = realpath(Storage::disk('local')->path($song->file_path));
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw ValidationException::withMessages(['audio' => 'Audio privado no disponible; importe o cargue el MP3.']);
        }

        return $path;
    }

    public function metadata(Song $song): array
    {
        $path = $this->path($song);
        $size = filesize($path);
        if ($size === false || $size > 32 * 1024 * 1024 || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'mp3') {
            throw ValidationException::withMessages(['audio' => 'Se requiere MP3 de hasta 32 MiB.']);
        }
        $file = fopen($path, 'rb');
        try {
            $header = fread($file, 10);
            $offset = 0;
            if (strlen($header) === 10 && substr($header, 0, 3) === 'ID3') {
                $offset = 10;
                $length = 0;
                for ($i = 6; $i < 10; $i++) {
                    $length = ($length << 7) | (ord($header[$i]) & 127);
                }
                $offset += $length;
                if (ord($header[3]) === 4 && (ord($header[5]) & 16)) {
                    $offset += 10;
                }
            }
            fseek($file, $offset);
            $bytes = fread($file, 65536);
        } finally {
            fclose($file);
        }
        // MPEG-1 Layer III at 44100 Hz; verify a second frame before advertising it.
        for ($i = 0; $i + 4 < strlen($bytes); $i++) {
            $a = ord($bytes[$i]);
            $b = ord($bytes[$i + 1]);
            $c = ord($bytes[$i + 2]);
            $rateIndex = $c >> 4;
            if ($a !== 255 || ($b & 254) !== 250 || ($c & 12) !== 0 || $rateIndex === 0 || $rateIndex === 15) {
                continue;
            }
            $bitrate = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320][$rateIndex] * 1000;
            $next = $i + intdiv(144 * $bitrate, 44100) + (($c >> 1) & 1);
            if ($next + 3 >= strlen($bytes) || ord($bytes[$next]) !== 255 || (ord($bytes[$next + 1]) & 254) !== 250 || (ord($bytes[$next + 2]) & 12) !== 0) {
                continue;
            }

            return ['song_id' => (string) $song->id, 'content_version' => hash_file('sha256', $path),
                'format' => ['container' => 'mp3', 'codec' => 'mp3', 'mime_type' => 'audio/mpeg',
                    'sample_rate_hz' => 44100, 'channels' => (ord($bytes[$i + 3]) >> 6) === 3 ? 1 : 2,
                    'duration_ms' => null, 'bit_rate_bps' => null]];
        }
        throw ValidationException::withMessages(['audio' => 'El perfil requiere MP3 MPEG-1 Layer III de 44100 Hz.']);
    }

    public function grant(Engine $engine): string
    {
        return hash('sha256', $engine->token_hash.'|'.$engine->session_id);
    }

    public function authorize(Engine $engine, array $song): array
    {
        $base = rtrim(config('engine.media_url'), '/');
        if (parse_url($base, PHP_URL_SCHEME) !== 'https' || ! parse_url($base, PHP_URL_HOST)) {
            throw ValidationException::withMessages(['audio' => 'ENGINE_MEDIA_URL debe ser HTTPS.']);
        }
        $expires = now()->addSeconds(config('engine.audio_url_seconds'));
        $urls = clone URL::getFacadeRoot();
        $urls->forceRootUrl($base);
        $urls->forceScheme('https');
        $song['audio_url'] = $urls->temporarySignedRoute('engine.audio', $expires, [
            'engine' => $engine->id, 'song' => $song['song_id'], 'version' => $song['content_version'],
            'grant' => $this->grant($engine),
        ]);
        $song['url_expires_at'] = $expires->toISOString();

        return $song;
    }
}
