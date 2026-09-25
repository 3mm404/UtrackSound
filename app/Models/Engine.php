<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Engine extends Model
{
    use HasFactory;

    protected $fillable = ['business_id', 'name', 'server_url', 'enabled'];

    protected $hidden = ['token_hash', 'session_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'last_seen_at' => 'datetime', 'capabilities' => 'array',
            'observed_state' => 'array', 'config_revision' => 'integer', 'report_sequence' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (Engine $engine): void {
            if ($engine->isDirty('business_id')) {
                throw ValidationException::withMessages(['business_id' => 'El negocio del equipo es inmutable. Cree otro equipo para cambiarlo.']);
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(EngineCommand::class);
    }

    public function latestCommand(): HasOne
    {
        return $this->hasOne(EngineCommand::class)->ofMany('sequence', 'max');
    }

    public function isOnline(): bool
    {
        return $this->enabled && $this->token_hash !== null && $this->last_seen_at?->gt(now()->subSeconds(30));
    }

    public function issueToken(): string
    {
        $token = Str::random(64);
        DB::transaction(function () use ($token): void {
            $engine = static::query()->lockForUpdate()->findOrFail($this->id);
            $engine->forceFill(['token_hash' => hash('sha256', $token), 'session_id' => null,
                'last_seen_at' => null, 'report_sequence' => 0])->save();
        });
        $this->refresh();

        return $token;
    }
}
