<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/v1/engine/*')) {
                return response()->json(['error' => [
                    'code' => 'validation_failed', 'message' => 'Datos inválidos.',
                    'details' => collect($e->errors())->flatMap(fn ($messages, $field) => collect($messages)->map(fn ($message) => ['field' => $field, 'message' => $message]))->values(),
                    'request_id' => (string) Str::uuid(),
                ]], 422);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/v1/engine/*')) {
                return response()->json(['error' => [
                    'code' => match ($e->getStatusCode()) {
                        404 => 'not_found', 429 => 'rate_limited', default => 'request_failed'
                    },
                    'message' => 'No se pudo procesar la petición.', 'details' => [],
                    'request_id' => (string) Str::uuid(),
                ]], $e->getStatusCode(), $e->getHeaders());
            }
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
