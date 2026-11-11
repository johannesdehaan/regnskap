<?php

namespace App\Enums;

enum FoodRuleEffect: string
{
    case Count = 'count';
    case Review = 'review';
    case Exclude = 'exclude';
}
