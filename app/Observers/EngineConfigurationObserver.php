<?php

namespace App\Observers;

use App\Events\EngineChanged;
use App\Models\Engine;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Model;

class EngineConfigurationObserver
{
    public function saved(Model $model): void
    {
        $this->notify($model);
    }

    public function deleted(Model $model): void
    {
        $this->notify($model);
    }

    private function notify(Model $model): void
    {
        if ($model instanceof Zone) {
            $ids = array_filter([$model->engine_id, $model->getOriginal('engine_id')]);
        } else {
            $ids = Engine::query()->pluck('id')->all();
        }
        foreach (array_unique($ids) as $id) {
            EngineChanged::dispatch((string) $id);
        }
    }
}
