<?php

use App\Models\Business;
use App\Models\Playlist;
use App\Models\Song;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('users')->insert(['id' => 1, 'name' => 'Demo', 'email' => 'demo@example.test', 'password' => 'unused']);
    DB::table('businesses')->insert(['id' => 1, 'name' => 'Hotel Demo']);
    DB::table('business_user')->insert(['business_id' => 1, 'user_id' => 1]);
    DB::table('zones')->insert(['id' => 1, 'business_id' => 1, 'name' => 'Lobby']);
    DB::table('playlists')->insert(['id' => 1, 'business_id' => 1, 'name' => 'Jazz']);
    DB::table('songs')->insert([
        ['id' => 1, 'title' => 'First', 'file_path' => 'first.mp3'],
        ['id' => 2, 'title' => 'Second', 'file_path' => 'second.mp3'],
    ]);
    DB::table('playlist_song')->insert([
        ['playlist_id' => 1, 'song_id' => 1, 'position' => 2],
        ['playlist_id' => 1, 'song_id' => 2, 'position' => 1],
    ]);
    DB::table('zones')->where('id', 1)->update(['playlist_id' => 1]);
});

it('supports the complete relationship path, defaults and song order', function () {
    expect((bool) DB::table('users')->value('is_super_admin'))->toBeFalse()
        ->and(DB::table('zones')->value('volume'))->toBe(100)
        ->and(DB::table('zones')->value('output_channel'))->toBeNull()
        ->and(DB::table('songs')->value('artist'))->toBeNull();

    $songs = DB::table('users')
        ->join('business_user', 'users.id', '=', 'business_user.user_id')
        ->join('businesses', 'business_user.business_id', '=', 'businesses.id')
        ->join('zones', 'businesses.id', '=', 'zones.business_id')

        ->join('playlists', 'zones.playlist_id', '=', 'playlists.id')
        ->join('playlist_song', 'playlists.id', '=', 'playlist_song.playlist_id')
        ->join('songs', 'playlist_song.song_id', '=', 'songs.id')
        ->where('users.id', 1)->orderBy('playlist_song.position')->pluck('songs.id')->all();

    expect($songs)->toBe([2, 1]);
});

it('rejects duplicate assignments', function (string $table, array $row) {
    DB::table($table)->insert($row);
})->with([
    ['business_user', ['business_id' => 1, 'user_id' => 1]],
    ['playlist_song', ['playlist_id' => 1, 'song_id' => 1, 'position' => 3]],

])->throws(QueryException::class);

it('rejects orphan foreign keys', function (string $table, array $row) {
    DB::table($table)->insert($row);
})->with([
    ['business_user', ['business_id' => 999, 'user_id' => 1]],
    ['business_user', ['business_id' => 1, 'user_id' => 999]],
    ['zones', ['business_id' => 999, 'name' => 'Orphan']],
    ['playlist_song', ['playlist_id' => 999, 'song_id' => 1, 'position' => 1]],
    ['playlist_song', ['playlist_id' => 1, 'song_id' => 999, 'position' => 1]],
    ['playlists', ['business_id' => 999, 'name' => 'Orphan']],
    ['zones', ['business_id' => 1, 'playlist_id' => 999, 'name' => 'Orphan']],
])->throws(QueryException::class);

it('cascades dependent rows and preserves shared entities', function (string $table, array $counts) {
    DB::table($table)->where('id', 1)->delete();

    foreach ($counts as $related => $count) {
        expect(DB::table($related)->count())->toBe($count);
    }
})->with([
    ['businesses', ['business_user' => 0, 'zones' => 0, 'users' => 1, 'playlists' => 0, 'playlist_song' => 0, 'songs' => 2]],
    ['users', ['business_user' => 0, 'businesses' => 1, 'zones' => 1]],
    ['zones', ['playlists' => 1, 'businesses' => 1]],
    ['playlists', ['playlist_song' => 0, 'zones' => 1, 'songs' => 2]],
    ['songs', ['playlist_song' => 1, 'playlists' => 1]],
]);

it('resolves all Eloquent relationships and ordered pivot data', function () {
    $user = User::findOrFail(1);
    $business = Business::findOrFail(1);
    $zone = Zone::findOrFail(1);
    $playlist = Playlist::findOrFail(1);
    $song = Song::findOrFail(1);
    expect($user->is_super_admin)->toBeFalse()
        ->and($user->businesses->modelKeys())->toBe([1])
        ->and($business->users->modelKeys())->toBe([1])
        ->and($business->zones->modelKeys())->toBe([1])
        ->and($business->playlists->modelKeys())->toBe([1])
        ->and($zone->business->id)->toBe(1)
        ->and($zone->playlist->id)->toBe(1)
        ->and($playlist->business->id)->toBe(1)
        ->and($playlist->zones->modelKeys())->toBe([1])
        ->and($playlist->songs->modelKeys())->toBe([2, 1])
        ->and($playlist->songs->first()->pivot->position)->toBe(1)
        ->and($song->playlists->modelKeys())->toBe([1])
        ->and($song->playlists->first()->pivot->position)->toBe(2);
});

it('preserves zones without an assigned playlist after playlist deletion', function () {
    Playlist::findOrFail(1)->delete();
    expect(Zone::findOrFail(1)->playlist_id)->toBeNull()
        ->and(Zone::findOrFail(1)->playlist)->toBeNull();
});

it('supports multiple businesses and more than 32 zones', function () {
    DB::table('businesses')->insert(['id' => 2, 'name' => 'Restaurant']);
    DB::table('playlists')->insert(['id' => 2, 'business_id' => 2, 'name' => 'Lounge']);
    for ($i = 0; $i < 33; $i++) {
        DB::table('zones')->insert(['business_id' => 2, 'name' => 'Zone '.$i, 'output_channel' => '1-2']);
    }
    $business = Business::findOrFail(2);
    expect($business->zones()->count())->toBe(33)
        ->and($business->playlists->modelKeys())->toBe([2])
        ->and($business->zones->first()->playlist)->toBeNull();
});

it('exposes the required indexes and foreign key actions', function () {
    $schema = Schema::getFacadeRoot();
    expect($schema->hasTable('zone_playlist'))->toBeFalse();
    foreach ([['business_user', ['business_id', 'user_id'], true], ['playlist_song', ['playlist_id', 'song_id'], true], ['playlist_song', ['playlist_id', 'position'], false]] as [$table, $columns, $unique]) {
        $index = collect($schema->getIndexes($table))->first(fn ($index) => $index['columns'] === $columns);
        expect($index)->not->toBeNull()->and($index['unique'])->toBe($unique);
    }
    foreach (['zones' => ['business_id' => 'cascade', 'playlist_id' => 'set null'], 'playlists' => ['business_id' => 'cascade'], 'business_user' => ['business_id' => 'cascade', 'user_id' => 'cascade'], 'playlist_song' => ['playlist_id' => 'cascade', 'song_id' => 'cascade']] as $table => $columns) {
        $keys = collect($schema->getForeignKeys($table));
        foreach ($columns as $column => $action) {
            $key = $keys->first(fn ($key) => $key['columns'] === [$column]);
            expect($key)->not->toBeNull()->and(strtolower($key['on_delete']))->toBe($action);
        }
    }
});

it('rolls back and reapplies the MVP migrations in dependency order', function () {
    $this->artisan('migrate:rollback', ['--step' => 7])->assertSuccessful();
    expect(Schema::hasTable('zones'))->toBeFalse();
    $this->artisan('migrate')->assertSuccessful();
    expect(Schema::hasColumn('zones', 'playlist_id'))->toBeTrue();
});
