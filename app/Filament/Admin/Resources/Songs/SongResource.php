<?php

namespace App\Filament\Admin\Resources\Songs;

use App\Filament\Admin\Resources\Songs\Pages\CreateSong;
use App\Filament\Admin\Resources\Songs\Pages\EditSong;
use App\Filament\Admin\Resources\Songs\Pages\ListSongs;
use App\Filament\Admin\Resources\Songs\Schemas\SongForm;
use App\Filament\Admin\Resources\Songs\Tables\SongsTable;
use App\Models\Song;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SongResource extends Resource
{
    protected static ?string $model = Song::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return SongForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SongsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSongs::route('/'),
            'create' => CreateSong::route('/create'),
            'edit' => EditSong::route('/{record}/edit'),
        ];
    }
}
