<?php

namespace App\Filament\Admin\Resources\Zones\Pages;

use App\Filament\Admin\Resources\Zones\ZoneResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageZones extends ManageRecords
{
    protected static string $resource = ZoneResource::class;

    protected string $view = 'filament.admin.resources.zones.pages.manage-zones';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
