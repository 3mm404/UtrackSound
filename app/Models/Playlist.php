<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Playlist extends Model
{
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
        return $this->belongsToMany(Song::class)->withPivot('position')->withTimestamps()->orderByPivot('position')->orderBy('songs.id');
    }
}
