<?php

namespace Database\Factories;

use App\Models\HoldingPriceSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HoldingPriceSchedule>
 */
class HoldingPriceScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'times' => HoldingPriceSchedule::DEFAULT_TIMES,
            'weekdays' => HoldingPriceSchedule::DEFAULT_WEEKDAYS,
            'handled_until' => now()->startOfSecond(),
        ];
    }
}
