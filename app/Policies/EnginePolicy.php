<?php

namespace App\Policies;

use App\Models\Engine;
use App\Models\User;

class EnginePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_super_admin || $user->can('ViewAny:Engine');
    }

    public function view(User $user, Engine $engine): bool
    {
        return $user->is_super_admin || ($user->can('View:Engine') && $user->businesses()->whereKey($engine->business_id)->exists());
    }

    public function create(User $user): bool
    {
        return (bool) $user->is_super_admin;
    }

    public function update(User $user, Engine $engine): bool
    {
        return $user->is_super_admin || ($user->can('Update:Engine') && $user->businesses()->whereKey($engine->business_id)->exists());
    }

    public function delete(User $user, Engine $engine): bool
    {
        return (bool) $user->is_super_admin;
    }
}
