<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $user->id === $account->owner_id;
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function update(User $user, Account $account): bool
    {
        return $this->view($user, $account) && $account->archived_at === null && $account->system_role === null;
    }

    public function delete(User $user, Account $account): bool
    {
        return false;
    }
}
