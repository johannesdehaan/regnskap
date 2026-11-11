<?php

namespace App\Actions;

use App\Enums\Category;
use App\Enums\FoodRuleEffect;
use App\Enums\FoodRuleMatch;
use App\Models\FoodRule;
use App\Models\User;
use App\Support\FoodClassification;
use App\Support\FoodText;
use App\Support\NonFoodMovement;
use Illuminate\Support\Collection;

class ClassifyFoodPurchase
{
    public function __construct(private NonFoodMovement $movements) {}

    public function __invoke(User $user, string $payee, ?string $note = null): FoodClassification
    {
        if ($this->movements->isIgnored($user, $payee, $note)) {
            return new FoodClassification(null, false);
        }

        $user->loadMissing('foodRules');

        /** @var Collection<int, FoodRule> $rules */
        $rules = $user->foodRules;

        $winner = $rules
            ->filter(fn (FoodRule $rule): bool => $this->matches($rule, $payee, $note))
            ->sortByDesc(fn (FoodRule $rule): int => ($rule->priority * 10000) + mb_strlen($rule->pattern))
            ->first();

        if (! $winner instanceof FoodRule) {
            return new FoodClassification(null, true);
        }

        return match ($winner->effect) {
            FoodRuleEffect::Count => new FoodClassification($winner->category ?? Category::Dagligvarer, false),
            FoodRuleEffect::Review => new FoodClassification(null, true),
            FoodRuleEffect::Exclude => new FoodClassification($winner->category, false),
        };
    }

    private function matches(FoodRule $rule, string $payee, ?string $note): bool
    {
        $haystack = $rule->match_type === FoodRuleMatch::StartsWith
            ? $payee
            : trim($payee.' '.($note ?? ''));

        return FoodText::matches($haystack, $rule->pattern, $rule->match_type);
    }
}
