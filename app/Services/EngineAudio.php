<?php

namespace App\Services;

use App\Models\Engine;
use App\Models\Song;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
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
            throw ValidationException::withMessages(['audio' => 'Audio privado no disponible; importe o cargue el archivo.']);
        }

        $size = filesize($path);
        if ($size === false || $size > 32 * 1024 * 1024) {
            throw ValidationException::withMessages(['audio' => 'Se requiere audio de hasta 32 MiB.']);
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'mp3' && $this->compatibleFormat($path) !== null) {
            return $path;
        }

        return $this->normalize($path);
    }

    public function metadata(Song $song): array
    {
        $path = $this->path($song);

        return ['song_id' => (string) $song->id, 'content_version' => hash_file('sha256', $path),
            'format' => $this->compatibleFormat($path)];
    }

    /** @return array{container: string, codec: string, mime_type: string, sample_rate_hz: int, channels: int, duration_ms: null, bit_rate_bps: null}|null */
    private function compatibleFormat(string $path): ?array
    {
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

            return ['container' => 'mp3', 'codec' => 'mp3', 'mime_type' => 'audio/mpeg',
                'sample_rate_hz' => 44100, 'channels' => (ord($bytes[$i + 3]) >> 6) === 3 ? 1 : 2,
                'duration_ms' => null, 'bit_rate_bps' => null];
        }

        return null;
    }

    private function normalize(string $source): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('engine-audio');
        $target = $disk->path('engine-audio/'.hash_file('sha256', $source).'-mp3-v1.mp3');
        $lock = fopen($target.'.lock', 'c');
        if ($lock === false) {
            throw ValidationException::withMessages(['audio' => 'No se pudo preparar el audio en el servidor.']);
        }
        $temporary = null;
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                throw ValidationException::withMessages(['audio' => 'El audio se está preparando. Intente reproducirlo de nuevo en unos segundos.']);
            }
            if (is_file($target) && filesize($target) <= 32 * 1024 * 1024 && $this->compatibleFormat($target) !== null) {
                return $target;
            }
            $temporary = tempnam(dirname($target), 'convert-');
            if ($temporary === false) {
                throw ValidationException::withMessages(['audio' => 'No se pudo crear el audio temporal en el servidor.']);
            }
            try {
                $result = Process::timeout(config('engine.audio_conversion_timeout'))->run([
                    config('engine.ffmpeg_path'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                    '-protocol_whitelist', 'file', '-f', $this->inputFormat($source), '-i', $source,
                    '-map', '0:a:0', '-vn', '-map_metadata', '-1', '-ac', '2', '-ar', '44100',
                    '-c:a', 'libmp3lame', '-b:a', '192k', '-threads', '1',
                    '-fs', '33554433', '-f', 'mp3', $temporary,
                ]);
            } catch (ProcessTimedOutException) {
                throw ValidationException::withMessages(['audio' => 'La conversión del audio excedió el tiempo permitido.']);
            }
            if ($result->failed()) {
                throw ValidationException::withMessages(['audio' => 'No se pudo convertir el audio. Compruebe que el archivo sea válido y que FFmpeg esté instalado en el servidor.']);
            }
            clearstatcache(true, $temporary);
            if (filesize($temporary) > 32 * 1024 * 1024) {
                throw ValidationException::withMessages(['audio' => 'El audio convertido supera los 32 MiB permitidos por el motor. Use una pista más corta.']);
            }
            if ($this->compatibleFormat($temporary) === null || ! rename($temporary, $target)) {
                throw ValidationException::withMessages(['audio' => 'No se pudo generar un MP3 compatible para el motor.']);
            }

            return $target;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function inputFormat(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp3' => 'mp3',
            'wav', 'wave' => 'wav',
            'flac' => 'flac',
            'aac' => 'aac',
            'm4a', 'mp4' => 'mov',
            'ogg', 'oga', 'opus' => 'ogg',
            default => throw ValidationException::withMessages(['audio' => 'Formato no admitido. Use MP3, WAV, FLAC, AAC, M4A u OGG.']),
        };
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
