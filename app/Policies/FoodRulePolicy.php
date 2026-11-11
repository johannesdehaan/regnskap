<?php

namespace App\Policies;

use App\Models\FoodRule;
use App\Models\User;

class FoodRulePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FoodRule $foodRule): bool
    {
        return $user->id === $foodRule->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FoodRule $foodRule): bool
    {
        return $user->id === $foodRule->user_id;
    }

    public function delete(User $user, FoodRule $foodRule): bool
    {
        return $user->id === $foodRule->user_id;
    }

    public function restore(User $user, FoodRule $foodRule): bool
    {
        return false;
    }

    public function forceDelete(User $user, FoodRule $foodRule): bool
    {
        return false;
    }
}
