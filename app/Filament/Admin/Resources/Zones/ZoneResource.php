<?php

namespace App\Filament\Admin\Resources\Zones;

use App\Filament\Admin\Resources\Zones\Pages\ManageZones;
use App\Models\Business;
use App\Models\Engine;
use App\Models\Playlist;
use App\Models\Zone;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ZoneResource extends Resource
{
    protected static ?string $model = Zone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Zone';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('engine');
        $user = auth()->user();

        return $user->is_super_admin ? $query : $query->whereIn('business_id', $user->businesses()->select('businesses.id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('business_id')
                    ->options(fn () => auth()->user()->is_super_admin ? Business::pluck('name', 'id') : auth()->user()->businesses()->pluck('name', 'businesses.id'))
                    ->live()
                    ->required(),
                Select::make('engine_id')->label('Equipo')
                    ->options(fn (Get $get) => Engine::where('business_id', $get('business_id'))->pluck('name', 'id'))
                    ->searchable(),
                Select::make('playlist_id')
                    ->options(fn (Get $get) => Playlist::where('business_id', $get('business_id'))->pluck('name', 'id')),
                TextInput::make('name')
                    ->required(),
                TextInput::make('output_channel'),
                Select::make('channel_mode')->options(['mono' => 'Mono', 'stereo' => 'Estéreo'])->default('stereo')->required(),
                TextInput::make('volume')
                    ->required()
                    ->numeric()
                    ->integer()->minValue(0)->maxValue(100)
                    ->default(100),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('business.name')
                    ->label('Business'),
                TextEntry::make('playlist.name')
                    ->label('Playlist')
                    ->placeholder('-'),
                TextEntry::make('name'),
                TextEntry::make('output_channel')
                    ->placeholder('-'),
                TextEntry::make('volume')
                    ->numeric(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->recordTitleAttribute('Zone')
            ->columns([
                TextColumn::make('business.name')
                    ->searchable(),
                TextColumn::make('engine.name')->label('Equipo')->placeholder('Sin asignar'),
                TextColumn::make('applied_volume')->label('Volumen reportado')
                    ->state(fn (Zone $record) => collect($record->engine?->observed_state['zones'] ?? [])->firstWhere('zone_id', (string) $record->id)['volume'] ?? null)
                    ->placeholder('Pendiente'),
                TextColumn::make('applied_playlist')->label('Playlist reportada (ID)')
                    ->state(fn (Zone $record) => collect($record->engine?->observed_state['zones'] ?? [])->firstWhere('zone_id', (string) $record->id)['playlist_id'] ?? null)
                    ->placeholder('Sin playlist'),
                TextColumn::make('engine_online')->label('Estado del reporte')
                    ->state(fn (Zone $record): string => $record->engine?->isOnline() ? 'Actual' : 'Desactualizado'),
                TextColumn::make('playlist.name')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('output_channel')
                    ->searchable(),
                TextColumn::make('volume')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageZones::route('/'),
        ];
    }
}
