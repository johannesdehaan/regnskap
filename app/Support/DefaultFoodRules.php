<?php

namespace App\Support;

use App\Enums\Category;
use App\Enums\FoodRuleEffect;
use App\Enums\FoodRuleMatch;

class DefaultFoodRules
{
    /**
     * Startregler for matbudsjettet. Ekskludering har høyere prioritet enn
     * brede treff, og «Express …» (ubetjent drivstoff) ligger over bensinstasjon.
     *
     * @return list<array{pattern: string, effect: FoodRuleEffect, category: Category|null, priority: int, match_type: FoodRuleMatch}>
     */
    public static function definitions(): array
    {
        return [
            self::exclude('Coop Byggmix'),
            self::exclude('Obs Bygg'),
            self::exclude('Coop Elektro'),
            self::exclude('Vinmonopolet'),
            self::exclude('Express', Category::Bil, match: FoodRuleMatch::StartsWith),

            self::review('Circle K', 80),
            self::review('Esso', 80),
            self::review('Uno-X', 80),
            self::review('Unox', 80),
            self::review('YX', 80),
            self::review('Shell', 80),
            self::review('St1', 80),
            self::review('Bensinstasjon', 80),

            self::review('Europris', 70),
            self::review('Normal', 70),

            self::count('Rema', Category::Dagligvarer),
            self::count('Kiwi', Category::Dagligvarer),
            self::count('Meny', Category::Dagligvarer),
            self::count('Spar', Category::Dagligvarer),
            self::count('Eurospar', Category::Dagligvarer),
            self::count('Joker', Category::Dagligvarer),
            self::count('Nærbutikken', Category::Dagligvarer),
            self::count('Bunnpris', Category::Dagligvarer),
            self::count('Coop Extra', Category::Dagligvarer),
            self::count('Coop Prix', Category::Dagligvarer),
            self::count('Coop Mega', Category::Dagligvarer),
            self::count('Coop Marked', Category::Dagligvarer),
            self::count('Matkroken', Category::Dagligvarer),
            self::count('Oda', Category::Dagligvarer),
            self::count('Kolonial.no', Category::Dagligvarer),
            self::count('Holdbart', Category::Dagligvarer),
            self::count('Extra', Category::Dagligvarer, 40),
            self::count('Prix', Category::Dagligvarer, 40),
            self::count('Mega', Category::Dagligvarer, 40),
            self::count('Obs Hypermarked', Category::Dagligvarer),
            self::count('Coop Obs', Category::Dagligvarer),
            self::count('Obs', Category::Dagligvarer, 45),

            self::count('Burger King', Category::Servering),
            self::count('McDonald\'s', Category::Servering),
            self::count('Max', Category::Servering, 40),
            self::count('Peppes', Category::Servering),
            self::count('Dolly Dimple\'s', Category::Servering),
            self::count('Pizzabakeren', Category::Servering),
            self::count('Egon', Category::Servering, 40),
            self::count('Subway', Category::Servering),
            self::count('Starbucks', Category::Servering),
            self::count('Espresso House', Category::Servering),
            self::count('Waynes', Category::Servering),
            self::count('Baker Brun', Category::Servering),
            self::count('Bakehuset', Category::Servering),
            self::count('Foodora', Category::Servering),
            self::count('Wolt', Category::Servering),

            self::count('Narvesen', Category::Servering),
            self::count('7-Eleven', Category::Servering),
            self::count('Deli de Luca', Category::Servering),
            self::count('Mix', Category::Servering, 40),
            self::count('Kiosk', Category::Servering, 40),

            self::count('Restaurant', Category::Servering, 30),
            self::count('Pizza', Category::Servering, 30),
            self::count('Sushi', Category::Servering, 30),
            self::count('Kebab', Category::Servering, 30),
            self::count('Burger', Category::Servering, 30),
            self::count('Cafe', Category::Servering, 30),
            self::count('Kafe', Category::Servering, 30),
            self::count('Bakeri', Category::Servering, 30),
            self::count('Grill', Category::Servering, 30),
            self::count('Dagligvarer', Category::Dagligvarer, 30),
            self::count('Mat', Category::Dagligvarer, 30),
        ];
    }

    /**
     * @return array{pattern: string, effect: FoodRuleEffect, category: Category|null, priority: int, match_type: FoodRuleMatch}
     */
    private static function count(string $pattern, Category $category, int $priority = 50): array
    {
        return [
            'pattern' => $pattern,
            'effect' => FoodRuleEffect::Count,
            'category' => $category,
            'priority' => $priority,
            'match_type' => FoodRuleMatch::Contains,
        ];
    }

    /**
     * @return array{pattern: string, effect: FoodRuleEffect, category: null, priority: int, match_type: FoodRuleMatch}
     */
    private static function review(string $pattern, int $priority): array
    {
        return [
            'pattern' => $pattern,
            'effect' => FoodRuleEffect::Review,
            'category' => null,
            'priority' => $priority,
            'match_type' => FoodRuleMatch::Contains,
        ];
    }

    /**
     * @return array{pattern: string, effect: FoodRuleEffect, category: Category|null, priority: int, match_type: FoodRuleMatch}
     */
    private static function exclude(
        string $pattern,
        ?Category $category = null,
        int $priority = 100,
        FoodRuleMatch $match = FoodRuleMatch::Contains,
    ): array {
        return [
            'pattern' => $pattern,
            'effect' => FoodRuleEffect::Exclude,
            'category' => $category,
            'priority' => $priority,
            'match_type' => $match,
        ];
    }
}
