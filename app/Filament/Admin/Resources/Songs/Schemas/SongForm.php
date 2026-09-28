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
            FileUpload::make('file_path')->label('Audio')->disk('local')->directory('songs')
                ->visibility('private')->acceptedFileTypes([
                    'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/vnd.wave',
                    'audio/flac', 'audio/x-flac', 'audio/aac', 'audio/x-aac', 'audio/mp4',
                    'audio/x-m4a', 'audio/ogg', 'application/ogg', 'audio/opus',
                ])
                ->maxSize(32768)->helperText('MP3, WAV, FLAC, AAC, M4A u OGG, hasta 32 MiB. La frecuencia se adapta automáticamente para reproducirlo.')->required(),
        ]);
    }
}
