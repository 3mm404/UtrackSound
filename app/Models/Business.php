<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class);
    }
}
