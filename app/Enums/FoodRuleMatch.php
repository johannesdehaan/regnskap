<?php

namespace App\Enums;

enum FoodRuleMatch: string
{
    case Contains = 'contains';
    case StartsWith = 'starts_with';
}
