<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Song extends Model
{
    protected $fillable = [
        'title',
        'artist',
        'file_path',
    ];

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class)->using(PlaylistSong::class)->withPivot('position')->withTimestamps();
    }
}
