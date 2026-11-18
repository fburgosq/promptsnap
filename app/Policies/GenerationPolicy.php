<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }
}
