<?php

namespace App\Policies;

use App\Models\FoodBudgetAddition;
use App\Models\User;

class FoodBudgetAdditionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, FoodBudgetAddition $foodBudgetAddition): bool
    {
        return $user->id === $foodBudgetAddition->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, FoodBudgetAddition $foodBudgetAddition): bool
    {
        return $user->id === $foodBudgetAddition->user_id;
    }

    public function delete(User $user, FoodBudgetAddition $foodBudgetAddition): bool
    {
        return $user->id === $foodBudgetAddition->user_id;
    }

    public function restore(User $user, FoodBudgetAddition $foodBudgetAddition): bool
    {
        return false;
    }

    public function forceDelete(User $user, FoodBudgetAddition $foodBudgetAddition): bool
    {
        return false;
    }
}
