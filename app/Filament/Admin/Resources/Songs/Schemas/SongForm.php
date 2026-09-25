<?php

namespace App\Filament\Admin\Resources\Songs\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SongForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),

                TextInput::make('artist')
                    ->maxLength(255),

                FileUpload::make('file_path')
                    ->label('Audio file')
                    ->disk('public')
                    ->directory('songs')
                    ->visibility('public')
                    ->acceptedFileTypes([
                        'audio/mpeg',
                        'audio/mp3',
                        'audio/wav',
                        'audio/x-wav',
                        'audio/wave',
                    ])
                    ->maxSize(61440)
                    ->required(),
            ]);
    }
}