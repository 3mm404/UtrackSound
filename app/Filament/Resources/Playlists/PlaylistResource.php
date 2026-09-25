<?php

namespace App\Filament\Resources\Playlists;

use App\Filament\Resources\Playlists\Pages\CreatePlaylist;
use App\Filament\Resources\Playlists\Pages\EditPlaylist;
use App\Filament\Resources\Playlists\Pages\ListPlaylists;
use App\Filament\Resources\Playlists\Pages\ViewPlaylist;
use App\Filament\Resources\Playlists\Schemas\PlaylistForm;
use App\Filament\Resources\Playlists\Schemas\PlaylistInfolist;
use App\Filament\Resources\Playlists\Tables\PlaylistsTable;
use App\Models\Playlist;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlaylistResource extends Resource
{
    protected static ?string $model = Playlist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Playlisst';

    public static function form(Schema $schema): Schema
    {
        return PlaylistForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PlaylistInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlaylistsTable::configure($table);
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
            'index' => ListPlaylists::route('/'),
            'create' => CreatePlaylist::route('/create'),
            'view' => ViewPlaylist::route('/{record}'),
            'edit' => EditPlaylist::route('/{record}/edit'),
        ];
    }
}
