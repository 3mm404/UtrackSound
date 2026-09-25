<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class EngineChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $deviceId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('engines.'.$this->deviceId)];
    }

    public function broadcastAs(): string
    {
        return 'engine.changed';
    }

    public function broadcastWith(): array
    {
        return ['device_id' => $this->deviceId];
    }
}
