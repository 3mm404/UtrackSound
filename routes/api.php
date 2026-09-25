<?php

use App\Http\Controllers\EngineController;
use App\Http\Middleware\AuthenticateEngine;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/engine')->name('engine.')->middleware(['throttle:engine', AuthenticateEngine::class])->group(function (): void {
    Route::post('sessions', [EngineController::class, 'session'])->name('sessions');
    Route::get('config', [EngineController::class, 'configuration'])->name('config');
    Route::get('commands', [EngineController::class, 'commands'])->name('commands');
    Route::put('commands/{commandId}/result', [EngineController::class, 'result'])->name('result');
    Route::post('heartbeat', [EngineController::class, 'heartbeat'])->name('heartbeat');
    Route::post('broadcasting/auth', [EngineController::class, 'broadcastAuth'])->name('broadcast-auth');
});
