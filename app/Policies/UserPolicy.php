<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('access-admin');
    }

    public function view(User $user, User $owner): bool
    {
        return $user->is($owner);
    }

    public function update(User $user, User $owner): bool
    {
        return $user->is($owner);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, User $owner): bool
    {
        return $user->is($owner);
    }
}
