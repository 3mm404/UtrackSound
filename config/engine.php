<?php

return [
    'media_url' => env('ENGINE_MEDIA_URL', env('APP_URL')),
    'audio_url_seconds' => max(1, (int) env('ENGINE_AUDIO_URL_SECONDS', 300)),
    'websocket_url' => env('ENGINE_WEBSOCKET_URL', 'ws://127.0.0.1:8080'),
    'poll_interval_seconds' => max(1, (int) env('ENGINE_POLL_INTERVAL_SECONDS', 5)),
];
