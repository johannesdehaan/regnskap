<?php

namespace App\Actions;

use App\Models\User;
use App\Support\DefaultFoodRules;

class SeedFoodRules
{
    public function __invoke(User $user): void
    {
        foreach (DefaultFoodRules::definitions() as $rule) {
            $user->foodRules()->firstOrCreate(
                [
                    'pattern' => $rule['pattern'],
                    'match_type' => $rule['match_type']->value,
                ],
                [
                    'effect' => $rule['effect']->value,
                    'category' => $rule['category']?->value,
                    'priority' => $rule['priority'],
                ],
            );
        }
    }
}
