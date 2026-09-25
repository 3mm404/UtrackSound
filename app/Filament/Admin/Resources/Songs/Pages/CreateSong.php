<?php

namespace App\Filament\Admin\Resources\Songs\Pages;

use App\Filament\Admin\Resources\Songs\SongResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSong extends CreateRecord
{
    protected static string $resource = SongResource::class;
}
