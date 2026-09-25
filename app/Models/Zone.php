<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Zone extends Model
{
    protected $fillable = [
        'name',
        'business_id',
        'playlist_id',
        'output_channel',
        'volume',
        'engine_id',
        'channel_mode',
    ];

    protected static function booted(): void
    {
        static::saving(function (Zone $zone): void {
            $errors = [];
            if ($zone->engine_id && ! Engine::whereKey($zone->engine_id)->where('business_id', $zone->business_id)->exists()) {
                $errors['engine_id'] = 'El equipo debe pertenecer al mismo negocio.';
            }
            if ($zone->playlist_id && ! Playlist::whereKey($zone->playlist_id)->where('business_id', $zone->business_id)->exists()) {
                $errors['playlist_id'] = 'La playlist debe pertenecer al mismo negocio.';
            }
            if ($zone->volume !== null && (filter_var($zone->volume, FILTER_VALIDATE_INT) === false || $zone->volume < 0 || $zone->volume > 100)) {
                $errors['volume'] = 'El volumen debe ser un entero entre 0 y 100.';
            }
            if ($zone->channel_mode !== null && ! in_array($zone->channel_mode, ['mono', 'stereo'], true)) {
                $errors['channel_mode'] = 'Modo de canal inválido.';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
        });
    }

    public function engine(): BelongsTo
    {
        return $this->belongsTo(Engine::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class);
    }
}
