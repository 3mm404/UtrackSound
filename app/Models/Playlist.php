<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Playlist extends Model
{
    protected static function booted(): void
    {
        static::updating(function (Playlist $playlist): void {
            if ($playlist->isDirty('business_id') && $playlist->zones()->exists()) {
                throw ValidationException::withMessages(['business_id' => 'Desasigne las zonas antes de cambiar el negocio.']);
            }
        });
    }

    protected $fillable = [
        'name',
        'business_id',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function songs(): BelongsToMany
    {
        return $this->belongsToMany(Song::class)->using(PlaylistSong::class)->withPivot('position')->withTimestamps()->orderByPivot('position')->orderBy('songs.id');
    }
}
