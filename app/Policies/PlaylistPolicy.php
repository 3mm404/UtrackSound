<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Playlist;
use Illuminate\Auth\Access\HandlesAuthorization;

class PlaylistPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Playlist');
    }

    public function view(AuthUser $authUser, Playlist $playlist): bool
    {
        return $authUser->can('View:Playlist');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Playlist');
    }

    public function update(AuthUser $authUser, Playlist $playlist): bool
    {
        return $authUser->can('Update:Playlist');
    }

    public function delete(AuthUser $authUser, Playlist $playlist): bool
    {
        return $authUser->can('Delete:Playlist');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Playlist');
    }

    public function restore(AuthUser $authUser, Playlist $playlist): bool
    {
        return $authUser->can('Restore:Playlist');
    }

    public function forceDelete(AuthUser $authUser, Playlist $playlist): bool
    {
        return $authUser->can('ForceDelete:Playlist');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Playlist');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Playlist');
    }

    public function replicate(AuthUser $authUser, Playlist $playlist): bool
    {
        return $authUser->can('Replicate:Playlist');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Playlist');
    }

}