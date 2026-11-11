<?php

namespace App\Models;

use App\Enums\Category;
use App\Enums\FoodRuleEffect;
use App\Enums\FoodRuleMatch;
use Database\Factories\FoodRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $pattern
 * @property FoodRuleMatch $match_type
 * @property FoodRuleEffect $effect
 * @property Category|null $category
 * @property int $priority
 */
#[Fillable(['user_id', 'pattern', 'match_type', 'effect', 'category', 'priority'])]
class FoodRule extends Model
{
    /** @use HasFactory<FoodRuleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_type' => FoodRuleMatch::class,
            'effect' => FoodRuleEffect::class,
            'category' => Category::class,
            'priority' => 'integer',
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
