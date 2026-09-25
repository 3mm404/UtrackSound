<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Business;
use Illuminate\Auth\Access\HandlesAuthorization;

class BusinessPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Business');
    }

    public function view(AuthUser $authUser, Business $business): bool
    {
        return $authUser->can('View:Business');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Business');
    }

    public function update(AuthUser $authUser, Business $business): bool
    {
        return $authUser->can('Update:Business');
    }

    public function delete(AuthUser $authUser, Business $business): bool
    {
        return $authUser->can('Delete:Business');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Business');
    }

    public function restore(AuthUser $authUser, Business $business): bool
    {
        return $authUser->can('Restore:Business');
    }

    public function forceDelete(AuthUser $authUser, Business $business): bool
    {
        return $authUser->can('ForceDelete:Business');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Business');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Business');
    }

    public function replicate(AuthUser $authUser, Business $business): bool
    {
        return $authUser->can('Replicate:Business');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Business');
    }

}