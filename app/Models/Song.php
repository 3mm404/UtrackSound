<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Song extends Model
{
    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class)->withPivot('position')->withTimestamps();
    }
}
