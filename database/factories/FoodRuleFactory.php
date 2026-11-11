<?php

namespace Database\Factories;

use App\Enums\Category;
use App\Enums\FoodRuleEffect;
use App\Enums\FoodRuleMatch;
use App\Models\FoodRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FoodRule>
 */
class FoodRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'pattern' => fake()->unique()->word(),
            'match_type' => FoodRuleMatch::Contains,
            'effect' => FoodRuleEffect::Count,
            'category' => Category::Dagligvarer,
            'priority' => 50,
        ];
    }
}
