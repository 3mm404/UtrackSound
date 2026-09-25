<?php

namespace App\Filament\Admin\Resources\Playlists\RelationManagers;

use App\Filament\Admin\Resources\Songs\SongResource;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SongsRelationManager extends RelationManager
{
    protected static string $relationship = 'songs';

    protected static ?string $relatedResource = SongResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Song'),

                TextColumn::make('artist')
                    ->label('Artist'),

                TextColumn::make('pivot.position')
                    ->label('Position'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Add song')
                    ->preloadRecordSelect(),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label('Remove'),
            ]);
    }
}