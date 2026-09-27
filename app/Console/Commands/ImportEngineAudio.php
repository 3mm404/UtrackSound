<?php

namespace App\Console\Commands;

use App\Models\Song;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('engine:import-audio')]
#[Description('Mueve los archivos de canciones del disco público al privado, verificando su contenido')]
class ImportEngineAudio extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $public = Storage::disk('public');
        $private = Storage::disk('local');
        foreach (Song::query()->cursor() as $song) {
            $relative = $song->file_path;
            if (! $public->exists($relative)) {
                continue;
            }
            $root = realpath($public->path('songs'));
            $path = realpath($public->path($relative));
            if (! $root || ! $path || ! is_file($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) ||
                ! preg_match('~\Asongs/[a-zA-Z0-9_.-]+\z~', $relative)) {
                $this->error('Ruta no admitida para canción '.$song->id);

                return self::FAILURE;
            }
            if (! $private->exists($relative)) {
                $stream = fopen($path, 'rb');
                try {
                    if (! $private->put($relative, $stream)) {
                        return self::FAILURE;
                    }
                } finally {
                    fclose($stream);
                }
            }
            $destination = realpath($private->path($relative));
            $privateRoot = realpath($private->path('songs'));
            if (! $destination || ! $privateRoot || ! str_starts_with($destination, $privateRoot.DIRECTORY_SEPARATOR) ||
                ! hash_equals(hash_file('sha256', $path), hash_file('sha256', $destination))) {
                $this->error('Conflicto de contenido para canción '.$song->id.'; original conservado.');

                return self::FAILURE;
            }
            if (! $public->delete($relative)) {
                return self::FAILURE;
            }
            $this->info('Canción '.$song->id.' en almacenamiento privado.');
        }

        return self::SUCCESS;
    }
}
