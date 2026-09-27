<?php

namespace App\Filament\Admin\Resources\Songs\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SongForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            TextInput::make('artist')->maxLength(255),
            FileUpload::make('file_path')->label('Audio MP3')->disk('local')->directory('songs')
                ->visibility('private')->acceptedFileTypes(['audio/mpeg', 'audio/mp3'])
                ->maxSize(32768)->helperText('MP3 a 44100 Hz, hasta 32 MiB.')->required(),
        ]);
    }
}
