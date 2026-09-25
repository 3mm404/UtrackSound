<?php

namespace App\Filament\Admin\Resources\Songs\Pages;

use App\Filament\Admin\Resources\Songs\SongResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSong extends EditRecord
{
    protected static string $resource = SongResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
