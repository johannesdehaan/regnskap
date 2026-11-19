<?php

namespace Database\Factories;

use App\Models\FoodBudgetAddition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FoodBudgetAddition>
 */
class FoodBudgetAdditionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Gjester på besøk',
            'starts_on' => '2026-03-01',
            'ends_on' => '2026-03-07',
            'amount_per_day' => '40.00',
        ];
    }
}
