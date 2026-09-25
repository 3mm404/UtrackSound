<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EngineCommand extends Model
{
    use HasUuids;

    protected $fillable = ['engine_id', 'sequence', 'zone_id', 'config_revision', 'action', 'expires_at'];

    protected function casts(): array
    {
        return ['result' => 'array', 'expires_at' => 'datetime'];
    }
}
