<?php

use App\Filament\Admin\Resources\Engines\Pages\ManageEngines;
use App\Filament\Admin\Resources\Zones\Pages\ManageZones;
use App\Models\Engine;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\User;
use App\Models\Zone;
use App\Policies\EnginePolicy;
use App\Services\EngineAudio;
use App\Services\EngineConfiguration;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function zoneControlFixture(): array
{
    config(['engine.media_url' => 'https://localhost']);
    Storage::fake('local');
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    test()->actingAs($admin);
    $engine = Engine::factory()->create();
    $engine->forceFill(['engine_version' => '0.6.0', 'capabilities' => [EngineAudio::PROFILE]])->save();
    $playlist = Playlist::create(['business_id' => $engine->business_id, 'name' => 'Local']);
    $song = Song::create(['title' => 'Seleccionada', 'file_path' => 'songs/control.mp3']);
    Storage::disk('local')->put($song->file_path, str_repeat("\xff\xfb\x90\x00".str_repeat("\0", 413), 3));
    $playlist->songs()->attach($song, ['position' => 1]);
    $zone = Zone::create(['business_id' => $engine->business_id, 'engine_id' => $engine->id,
        'playlist_id' => $playlist->id, 'name' => 'Control', 'volume' => 20]);

    return [$engine, $zone->refresh(), $song];
}

it('sends each transport from the zone without confirming execution prematurely', function (string $action) {
    [$engine, $zone, $song] = zoneControlFixture();
    Livewire::test(ManageZones::class)->assertSuccessful()
        ->callTableAction($action, $zone, $action === 'play' ? ['song_id' => (string) $song->id] : [])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Orden enviada — pendiente de confirmación');
    $this->assertDatabaseHas('engine_commands', ['engine_id' => $engine->id, 'zone_id' => (string) $zone->id,
        'action' => $action, 'song_id' => $action === 'play' ? (string) $song->id : null, 'result' => null]);
    expect($engine->commands()->sole()->statusLabel())->toBe('Pendiente de confirmación');
})->with(['play', 'pause', 'resume', 'stop', 'next', 'previous']);

it('rejects a forged song or an old agent without creating a command', function (string $failure) {
    [$engine, $zone, $song] = zoneControlFixture();
    if ($failure === 'old') {
        $engine->forceFill(['engine_version' => '0.5.0'])->save();
    }
    expect(fn () => app(EngineConfiguration::class)->enqueue($engine, (string) $zone->id, 'play', $failure === 'foreign' ? '999999' : (string) $song->id))
        ->toThrow(ValidationException::class, $failure === 'foreign' ? 'La canción no pertenece' : 'Actualiza el engine');
    $this->assertDatabaseCount('engine_commands', 0);
})->with(['foreign', 'old']);

it('changes only the chosen zones settings and waits for its report', function () {
    [$engine, $zone] = zoneControlFixture();
    $other = Zone::create(['business_id' => $zone->business_id, 'engine_id' => $engine->id, 'name' => 'Otra', 'volume' => 70]);
    Livewire::test(ManageZones::class)->callTableAction('settings', $zone, ['volume' => 35, 'playlist_id' => null])
        ->assertHasNoTableActionErrors()->assertNotified('Ajustes guardados — pendientes de confirmación');
    $this->assertDatabaseHas('zones', ['id' => $zone->id, 'volume' => 35, 'playlist_id' => null]);
    expect($other->refresh()->volume)->toBe(70);
    expect($zone->refresh()->configurationStatus())->toBe('Sin conexión — pendiente de verificar');
    $engine->issueToken();
    $engine->forceFill(['last_seen_at' => now(), 'observed_state' => ['applied_config_revision' => $engine->config_revision,
        'zones' => [['zone_id' => (string) $zone->id, 'volume' => 35, 'playlist_id' => null]]]])->save();
    expect($zone->refresh()->configurationStatus())->toBe('Configuración confirmada');
});

it('rejects invalid volume and foreign playlists from the zone settings', function (string $field) {
    [$engine, $zone] = zoneControlFixture();
    $foreign = Engine::factory()->create();
    $playlist = Playlist::create(['business_id' => $foreign->business_id, 'name' => 'Ajena']);
    Livewire::test(ManageZones::class)->callTableAction('settings', $zone, [
        'volume' => $field === 'volume' ? 101 : 20,
        'playlist_id' => $field === 'playlist_id' ? $playlist->id : $zone->playlist_id,
    ])->assertHasTableActionErrors([$field]);
    $this->assertDatabaseHas('zones', ['id' => $zone->id, 'volume' => 20, 'playlist_id' => $zone->playlist_id]);
})->with(['volume', 'playlist_id']);

it('hides controls from users without permission to update the engine', function () {
    [$engine, $zone] = zoneControlFixture();
    $user = User::factory()->create();
    foreach (['ViewAny:Zone', 'Update:Zone'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }
    $user->businesses()->attach($engine->business_id);
    $this->actingAs($user);
    Livewire::test(ManageZones::class)->assertTableActionHidden('play', $zone)->assertTableActionHidden('settings', $zone);
    $this->assertDatabaseCount('engine_commands', 0);
});

it('shows confirmed failed and overdue commands for their own zone', function () {
    $this->freezeTime();
    [$engine, $zone] = zoneControlFixture();
    $command = app(EngineConfiguration::class)->enqueueStop($engine, (string) $zone->id);
    $this->travel(61)->seconds();
    expect($command->statusLabel())->toBe('Plazo vencido — sin confirmación');
    $command->forceFill(['result' => ['outcome' => 'failed', 'error' => ['code' => 'no_track', 'message' => 'Sin pista cargada']]])->save();
    Livewire::test(ManageZones::class)->assertSuccessful()
        ->assertTableActionExists('history', record: $zone);
    expect($command->fresh()->statusLabel())->toBe('Fallida');
    $command->forceFill(['result' => ['outcome' => 'succeeded', 'error' => null]])->save();
    expect($command->fresh()->statusLabel())->toBe('Confirmada');
});

it('renders the engine panel for a super admin and denies an ordinary user', function () {
    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save();
    $engine = Engine::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($admin);
    Livewire::test(ManageEngines::class)->assertSuccessful()->assertCanSeeTableRecords([$engine])->assertSee('Desconectado');
    $this->actingAs(User::factory()->create());
    Livewire::test(ManageEngines::class)->assertForbidden();
});

it('limits engine access to permission and business membership', function () {
    $user = User::factory()->create();
    $engine = Engine::factory()->create();
    $policy = new EnginePolicy;
    expect($policy->update($user, $engine))->toBeFalse();
    Permission::create(['name' => 'Update:Engine', 'guard_name' => 'web']);
    $user->givePermissionTo('Update:Engine');
    expect($policy->update($user, $engine))->toBeFalse();
    $user->businesses()->attach($engine->business_id);
    expect($policy->update($user, $engine))->toBeTrue();
    expect($policy->create($user))->toBeFalse();
});

it('rejects cross-business zone assignments and out-of-range volume', function (string $field) {
    $engine = Engine::factory()->create();
    $other = Engine::factory()->create();
    $playlist = Playlist::create(['business_id' => $other->business_id, 'name' => 'Foreign']);
    $values = ['business_id' => $engine->business_id, 'name' => 'Lobby', 'volume' => 50];
    $values[$field] = match ($field) {
        'engine_id' => $other->id, 'playlist_id' => $playlist->id, 'volume' => 101
    };
    expect(fn () => Zone::create($values))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('zones', 0);
})->with(['engine_id', 'playlist_id', 'volume']);

it('prevents moving an engine to a different business', function () {
    $engine = Engine::factory()->create();
    $other = Engine::factory()->create();
    expect(fn () => $engine->update(['business_id' => $other->business_id]))->toThrow(ValidationException::class);
});

it('shows reported zone state and limits the list to the users businesses', function () {
    $user = User::factory()->create();
    Permission::create(['name' => 'ViewAny:Zone', 'guard_name' => 'web']);
    $user->givePermissionTo('ViewAny:Zone');
    $engine = Engine::factory()->create();
    $other = Engine::factory()->create();
    $user->businesses()->attach($engine->business_id);
    $zone = Zone::create(['name' => 'Lobby visible', 'business_id' => $engine->business_id, 'engine_id' => $engine->id, 'volume' => 65]);
    $foreign = Zone::create(['name' => 'Lobby privado', 'business_id' => $other->business_id, 'engine_id' => $other->id]);
    $engine->issueToken();
    $engine->forceFill(['last_seen_at' => now(), 'observed_state' => ['zones' => [
        ['zone_id' => (string) $zone->id, 'volume' => 35, 'playlist_id' => '20',
            'state' => 'error', 'song_id' => '30', 'channel_mode' => 'mono',
            'error' => ['code' => 'audio_download_failed', 'message' => 'Tiempo de espera agotado']],
    ]]])->save();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($user);

    Livewire::test(ManageZones::class)->assertSuccessful()
        ->assertCanSeeTableRecords([$zone])->assertCanNotSeeTableRecords([$foreign])
        ->assertSee('35%')
        ->assertSee('Error')
        ->assertSee('Reporte actual')
        ->assertDontSee('Tiempo de espera agotado');
    $engine->forceFill(['last_seen_at' => now()->subSeconds(31)])->save();
    Livewire::test(ManageZones::class)->assertSee('Reporte desactualizado');
});
