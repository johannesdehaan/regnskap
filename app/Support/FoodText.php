<?php

namespace App\Support;

use App\Enums\FoodRuleMatch;
use Illuminate\Support\Str;

class FoodText
{
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(["\u{00A0}", '’', '‘', '`', '´', "'"], '', $text);
        $text = str_replace(['-', '‐', '–', '—', '/', '_'], ' ', $text);
        $text = Str::ascii($text);
        $text = preg_replace('/[^a-z0-9.\s]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;

        return $text;
    }

    public static function matches(string $haystack, string $pattern, FoodRuleMatch $match): bool
    {
        $haystack = self::normalize($haystack);
        $pattern = self::normalize($pattern);

        if ($pattern === '' || $haystack === '') {
            return false;
        }

        if ($match === FoodRuleMatch::StartsWith) {
            return $haystack === $pattern || str_starts_with($haystack, $pattern.' ');
        }

        $quoted = preg_quote($pattern, '/');
        $quoted = str_replace('\ ', '\s+', $quoted);

        return preg_match('/(?<![a-z0-9])'.$quoted.'(?![a-z0-9])/', $haystack) === 1;
    }
}
