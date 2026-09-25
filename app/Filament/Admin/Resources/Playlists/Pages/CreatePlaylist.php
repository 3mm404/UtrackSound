<?php

namespace App\Filament\Admin\Resources\Playlists\Pages;

use App\Filament\Admin\Resources\Playlists\PlaylistResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlaylist extends CreateRecord
{
    protected static string $resource = PlaylistResource::class;
}
