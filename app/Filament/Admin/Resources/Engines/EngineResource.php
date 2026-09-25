<?php

namespace App\Filament\Admin\Resources\Engines;

use App\Filament\Admin\Resources\Engines\Pages\ManageEngines;
use App\Models\Engine;
use App\Services\EngineConfiguration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class EngineResource extends Resource
{
    protected static ?string $model = Engine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'equipo';

    protected static ?string $pluralModelLabel = 'Engines';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user->is_super_admin ? $query : $query->whereIn('business_id', $user->businesses()->select('businesses.id'));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nombre')->required()->maxLength(255),
            Select::make('business_id')->label('Negocio')->relationship('business', 'name')->required()->disabledOn('edit'),
            TextInput::make('server_url')->label('Dirección de Laravel')->url()->required()->maxLength(255)
                ->default(config('app.url'))->helperText('Configura esta dirección como ENGINE_SERVER en el equipo.'),
            Toggle::make('enabled')->label('Habilitado')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->poll('10s')->columns([
            TextColumn::make('name')->label('Equipo')->searchable(),
            TextColumn::make('business.name')->label('Negocio'),
            TextColumn::make('connection')->label('Conexión')->state(fn (Engine $record): string => $record->isOnline() ? 'Conectado' : 'Desconectado')
                ->badge()->color(fn (Engine $record): string => $record->isOnline() ? 'success' : 'gray'),
            TextColumn::make('engine_version')->label('Versión')->placeholder('Sin registrar'),
            TextColumn::make('last_seen_at')->label('Última actividad')->dateTime()->placeholder('Nunca'),
            TextColumn::make('config_revision')->label('Revisión enviada'),
            TextColumn::make('observed_state.applied_config_revision')->label('Revisión aplicada')->placeholder('Pendiente'),
            TextColumn::make('observed_state.config_error.message')->label('Error de configuración')->placeholder('—')->wrap(),
            TextColumn::make('zones_count')->counts('zones')->label('Zonas'),
            TextColumn::make('commands_count')->counts(['commands' => fn (Builder $query) => $query->whereNull('result')])->label('Órdenes pendientes'),
            TextColumn::make('latestCommand.result.outcome')->label('Último resultado')->placeholder('Sin confirmar'),
        ])->recordActions([
            EditAction::make(),
            Action::make('credential')->label('Emitir credencial')->requiresConfirmation()
                ->modalDescription('Invalida la credencial anterior. Copia la nueva desde la notificación; no se vuelve a mostrar.')
                ->visible(fn (Engine $record): bool => Gate::allows('update', $record))
                ->action(function (Engine $record): void {
                    Gate::authorize('update', $record);
                    Notification::make()->title('Nueva credencial — copiar ahora')->body($record->issueToken())->persistent()->send();
                }),
            Action::make('stop')->label('Enviar stop')->schema([
                Select::make('zone_id')->label('Zona')->options(fn (Engine $record): array => $record->zones()->pluck('name', 'id')->all())->required(),
            ])->visible(fn (Engine $record): bool => Gate::allows('update', $record))
                ->action(function (Engine $record, array $data, EngineConfiguration $configuration): void {
                    Gate::authorize('update', $record);
                    $configuration->enqueueStop($record, (string) $data['zone_id']);
                    Notification::make()->title('Orden pendiente de confirmación')->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEngines::route('/')];
    }
}
