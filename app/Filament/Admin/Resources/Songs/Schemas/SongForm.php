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
                //aqui podre cambiar de lugar el file upload para que quede en la parte de abajo del formulario
                //ajustar para la base de datos y el modelo para que acepte el campo file_path como un S3 
     FileUpload::make('file_path')
    ->label('Audio file')

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | LOCAL / VPS:
    | ->disk('public')
    | Los archivos se almacenan en:
    | storage/app/public/songs
    |
    | S3:
    | ->disk('s3')
    | Los archivos se almacenan en el bucket configurado en filesystems.php
    |
    | Recomendación futura:
    | ->disk(config('filesystems.audio_disk'))
    | Así podremos cambiar entre local, VPS y S3 desde .env.
    |
    */

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