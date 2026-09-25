<?php

namespace App\Providers;

use App\Models\Playlist;
use App\Models\PlaylistSong;
use App\Models\Song;
use App\Models\Zone;
use App\Observers\EngineConfigurationObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('engine', fn (Request $request) => Limit::perMinute(240)->by(hash('sha256', (string) $request->bearerToken()).'|'.$request->ip()));
        foreach ([Zone::class, Playlist::class, Song::class, PlaylistSong::class] as $model) {
            $model::observe(EngineConfigurationObserver::class);
        }
    }
}
