<?php

namespace App\Filament\Admin\Resources\Engines\Pages;

use App\Filament\Admin\Resources\Engines\EngineResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEngines extends ManageRecords
{
    protected static string $resource = EngineResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
