<?php

use App\Filament\Admin\Resources\Engines\Pages\ManageEngines;
use App\Filament\Admin\Resources\Zones\Pages\ManageZones;
use App\Models\Engine;
use App\Models\Playlist;
use App\Models\User;
use App\Models\Zone;
use App\Policies\EnginePolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

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
        ['zone_id' => (string) $zone->id, 'volume' => 35, 'playlist_id' => '20'],
    ]]])->save();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($user);

    Livewire::test(ManageZones::class)->assertSuccessful()
        ->assertCanSeeTableRecords([$zone])->assertCanNotSeeTableRecords([$foreign])
        ->assertTableColumnStateSet('applied_volume', 35, $zone)
        ->assertTableColumnStateSet('applied_playlist', '20', $zone)
        ->assertTableColumnStateSet('engine_online', 'Actual', $zone);
    $engine->forceFill(['last_seen_at' => now()->subSeconds(31)])->save();
    Livewire::test(ManageZones::class)->assertTableColumnStateSet('engine_online', 'Desactualizado', $zone);
});
