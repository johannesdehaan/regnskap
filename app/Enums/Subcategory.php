<?php

namespace App\Enums;

enum Subcategory: string
{
    case Matvarer = 'Matvarer';
    case BollerOgBrus = 'Boller og brus';
    case KaffeOgMelk = 'Kaffe og melk';
    case Husholdning = 'Husholdning';

    public function category(): Category
    {
        return match ($this) {
            self::Matvarer, self::BollerOgBrus, self::KaffeOgMelk, self::Husholdning => Category::Dagligvarer,
        };
    }

    public function countsAsFood(): bool
    {
        return match ($this) {
            self::Husholdning => false,
            self::Matvarer, self::BollerOgBrus, self::KaffeOgMelk => true,
        };
    }
}
