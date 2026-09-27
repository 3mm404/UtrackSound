<?php

namespace App\Filament\Admin\Resources\Zones;

use App\Filament\Admin\Resources\Zones\Pages\ManageZones;
use App\Models\Business;
use App\Models\Engine;
use App\Models\Playlist;
use App\Models\Zone;
use App\Services\EngineConfiguration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\RecordCheckboxPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ZoneResource extends Resource
{
    protected static ?string $model = Zone::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'zona';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['engine', 'business', 'playlist.songs', 'latestCommand']);
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
                Select::make('channel_mode')->options(['mono' => 'Mono', 'stereo' => 'Estéreo'])->default('stereo')->required()
                    ->helperText('Con salida compartida, el modo se elige en el engine y se muestra en el reporte.'),
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
            ->poll('5s')
            ->recordTitleAttribute('name')
            ->recordAction(null)

            ->contentGrid([
                'md' => 1,
                'lg' => 2,
            ])
            ->recordCheckboxPosition(RecordCheckboxPosition::AfterCells)

            ->columns([
                ViewColumn::make('player')
                    ->label('')
                    ->view('filament.zones.zone-player-card'),
            ])

            ->filters([
                //
            ])

            ->recordActions([
                self::playAction(),

                ...array_map(
                    fn (string $action, string $label): Action => self::transportAction($action, $label),

                    ['pause', 'resume', 'stop', 'next', 'previous'],

                    [
                        'Pausar',
                        'Reanudar',
                        'Detener',
                        'Siguiente',
                        'Anterior',
                    ]
                ),

                self::settingsAction(),

                Action::make('history')
                    ->label('Órdenes')
                    ->modalHeading('Historial de la zona')
                    ->modalContent(
                        fn (Zone $record) => view(
                            'filament.zone-command-history',
                            [
                                'commands' => $record->engine?->commands()
                                    ->where(
                                        'zone_id',
                                        (string) $record->id
                                    )
                                    ->orderByDesc('sequence')
                                    ->limit(20)
                                    ->get() ?? collect(),
                            ]
                        )
                    )
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),

                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make(),
                ])
                    ->label('Administrar'),
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

    private static function canControl(Zone $zone): bool
    {
        return Gate::allows('update', $zone) && $zone->engine !== null && Gate::allows('update', $zone->engine);
    }

    private static function sendCommand(Zone $zone, string $action, EngineConfiguration $configuration, ?string $songId = null): void
    {
        $zone->refresh();
        Gate::authorize('update', $zone);
        if (! $zone->engine) {
            throw ValidationException::withMessages(['engine_id' => 'Asigna un equipo a esta zona.']);
        }
        Gate::authorize('update', $zone->engine);
        try {
            $command = $configuration->enqueue($zone->engine, (string) $zone->id, $action, $songId);
        } catch (ValidationException $exception) {
            Notification::make()->title('No se pudo enviar la orden')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
            throw $exception;
        }
        Notification::make()->title('Orden enviada — pendiente de confirmación')
            ->body('Consulta el resultado en Última orden u Órdenes. Referencia: '.$command->sequence)->info()->send();
    }

    private static function playAction(): Action
    {
        return Action::make('play')->label('Reproducir')->icon('heroicon-o-play')
            ->modalSubmitActionLabel('Reproducir')->modalCancelActionLabel('Cancelar')
            ->visible(fn (Zone $record): bool => self::canControl($record))
            ->schema([
                Select::make('song_id')->label('Canción')->searchable()
                    ->placeholder('Reiniciar la canción seleccionada')
                    ->options(fn (Zone $record): array => $record->playlist?->songs()->pluck('title', 'songs.id')->all() ?? [])
                    ->helperText('Elige una canción de la playlist o deja vacío para reiniciar la selección actual.'),
            ])->action(fn (Zone $record, array $data, EngineConfiguration $configuration) => self::sendCommand($record, 'play', $configuration, filled($data['song_id'] ?? null) ? (string) $data['song_id'] : null));
    }

    private static function transportAction(string $action, string $label): Action
    {
        return Action::make($action)->label($label)
            ->visible(fn (Zone $record): bool => self::canControl($record))
            ->action(fn (Zone $record, EngineConfiguration $configuration) => self::sendCommand($record, $action, $configuration));
    }

    private static function settingsAction(): Action
    {
        return Action::make('settings')->label('Volumen / Playlist')
            ->modalSubmitActionLabel('Guardar ajustes')->modalCancelActionLabel('Cancelar')
            ->visible(fn (Zone $record): bool => self::canControl($record))
            ->fillForm(fn (Zone $record): array => ['volume' => $record->volume, 'playlist_id' => $record->playlist_id])
            ->schema([
                TextInput::make('volume')->label('Volumen (%)')->required()->numeric()->integer()->minValue(0)->maxValue(100),
                Select::make('playlist_id')->label('Playlist')->placeholder('Sin playlist')
                    ->options(fn (Zone $record): array => Playlist::where('business_id', $record->business_id)->pluck('name', 'id')->all())
                    ->helperText('Cambiar la playlist detiene esta zona. Después pulsa Reproducir.'),
            ])->action(function (Zone $record, array $data, EngineConfiguration $configuration): void {
                $record->refresh();
                Gate::authorize('update', $record);
                abort_unless($record->engine, 422, 'Asigna un equipo a esta zona.');
                Gate::authorize('update', $record->engine);
                try {
                    DB::transaction(function () use ($record, $data, $configuration): void {
                        $record->update(['volume' => $data['volume'], 'playlist_id' => $data['playlist_id'] ?: null]);
                        $configuration->snapshot($record->engine);
                    });
                } catch (ValidationException $exception) {
                    Notification::make()->title('No se pudieron guardar los ajustes')->body(collect($exception->errors())->flatten()->implode(' '))->danger()->send();
                    throw $exception;
                }
                Notification::make()->title('Ajustes guardados — pendientes de confirmación')->info()->send();
            });
    }
}
