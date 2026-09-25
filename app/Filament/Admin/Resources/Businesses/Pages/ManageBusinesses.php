<?php

namespace App\Filament\Admin\Resources\Businesses\Pages;

use App\Filament\Admin\Resources\Businesses\BusinessResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBusinesses extends ManageRecords
{
    protected static string $resource = BusinessResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
