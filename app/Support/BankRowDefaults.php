<?php

namespace App\Support;

use App\Enums\Category;

readonly class BankRowDefaults
{
    public function __construct(
        public ?Category $category,
        public bool $needsReview,
        public bool $excludeFromBudget,
    ) {}

    public function countsAsFood(): bool
    {
        return ! $this->excludeFromBudget
            && ! $this->needsReview
            && $this->category instanceof Category
            && $this->category->countsAsFood();
    }
}
