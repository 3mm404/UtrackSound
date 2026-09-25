<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Song;
use Illuminate\Auth\Access\HandlesAuthorization;

class SongPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Song');
    }

    public function view(AuthUser $authUser, Song $song): bool
    {
        return $authUser->can('View:Song');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Song');
    }

    public function update(AuthUser $authUser, Song $song): bool
    {
        return $authUser->can('Update:Song');
    }

    public function delete(AuthUser $authUser, Song $song): bool
    {
        return $authUser->can('Delete:Song');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Song');
    }

    public function restore(AuthUser $authUser, Song $song): bool
    {
        return $authUser->can('Restore:Song');
    }

    public function forceDelete(AuthUser $authUser, Song $song): bool
    {
        return $authUser->can('ForceDelete:Song');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Song');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Song');
    }

    public function replicate(AuthUser $authUser, Song $song): bool
    {
        return $authUser->can('Replicate:Song');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Song');
    }

}