<?php

namespace App\Models;

use Database\Factories\FoodBudgetAdditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Extra food budget for every day from starts_on to ends_on, both included.
 * The dates are calendar days in Europe/Oslo. A day adds amount_per_day to the
 * budget only while that day is inside the rolling window.
 *
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $amount_per_day
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'label', 'starts_on', 'ends_on', 'amount_per_day'])]
class FoodBudgetAddition extends Model
{
    /** @use HasFactory<FoodBudgetAdditionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'amount_per_day' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
