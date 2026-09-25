<?php

return [
    'websocket_url' => env('ENGINE_WEBSOCKET_URL', 'ws://127.0.0.1:8080'),
    'poll_interval_seconds' => max(1, (int) env('ENGINE_POLL_INTERVAL_SECONDS', 5)),
];
