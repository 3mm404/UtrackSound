<?php

namespace App\Filament\Admin\Resources\Playlists\Pages;

use App\Filament\Admin\Resources\Playlists\PlaylistResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPlaylist extends ViewRecord
{
    protected static string $resource = PlaylistResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
