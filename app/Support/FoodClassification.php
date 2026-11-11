<?php

namespace App\Support;

use App\Enums\Category;

readonly class FoodClassification
{
    public function __construct(
        public ?Category $category,
        public bool $needsReview,
    ) {}
}
