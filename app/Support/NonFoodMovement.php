<?php

namespace App\Support;

use App\Enums\FoodRuleMatch;
use App\Enums\PaymentMethod;
use App\Models\User;

/**
 * Inntekter, innbetaling av kredittkort og overføringer mellom egne kontoer
 * er ikke matforbruk, selv om teksten ellers ligner et kjøp.
 */
class NonFoodMovement
{
    public function isIgnored(User $user, string $payee, ?string $note): bool
    {
        $text = FoodText::normalize($payee.' '.($note ?? ''));

        if ($text === '') {
            return false;
        }

        if ($this->contains($text, 'lonn') || $this->contains($text, 'salary') || $this->contains($text, 'inntekt')) {
            return true;
        }

        $settlesCard = $this->contains($text, 'innbetaling') || $this->contains($text, 'nedbetaling');

        if ($settlesCard && str_contains($text, 'kreditt')) {
            return true;
        }

        if (str_contains($text, 'egen konto') || str_contains($text, 'egne konto') || str_contains($text, 'mellom egne')) {
            return true;
        }

        $payeeNorm = FoodText::normalize($payee);

        foreach ($this->ownNames($user) as $name) {
            if ($name !== '' && $payeeNorm === $name) {
                return true;
            }
        }

        if (! $this->looksLikeTransfer($text)) {
            return false;
        }

        foreach (['lonnskonto', 'brukskonto', 'sparekonto', 'hovedkonto', 'fakturakonto', 'regningskonto', 'debetkort', 'kredittkort'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        foreach ($this->ownNames($user) as $name) {
            if ($name !== '' && $this->contains($text, $name)) {
                return true;
            }
        }

        return false;
    }

    private function contains(string $normalizedHaystack, string $pattern): bool
    {
        return FoodText::matches($normalizedHaystack, $pattern, FoodRuleMatch::Contains);
    }

    private function looksLikeTransfer(string $normalized): bool
    {
        foreach (['overforing', 'vipps', 'straksbetaling', 'nettgiro'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function ownNames(User $user): array
    {
        $user->loadMissing('accounts');

        $names = [];

        foreach ($user->accounts as $account) {
            $names[] = FoodText::normalize($account->name);
        }

        foreach (PaymentMethod::cases() as $method) {
            $names[] = FoodText::normalize($method->value);
        }

        return array_values(array_unique(array_filter($names)));
    }
}
