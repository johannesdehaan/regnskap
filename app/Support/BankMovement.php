<?php

namespace App\Support;

/**
 * Overføringer mellom egne kontoer og innbetaling av kredittkort er ikke forbruk.
 */
class BankMovement
{
    /**
     * @param  list<string>  $ownAccountNumbers
     */
    public function isNonConsumption(
        string $payee,
        ?string $note,
        ?string $typeText,
        ?string $remoteAccountNumber,
        ?string $accountType,
        array $ownAccountNumbers,
    ): bool {
        $remote = $this->digits($remoteAccountNumber);

        if ($remote !== '' && in_array($remote, $ownAccountNumbers, true)) {
            return true;
        }

        $text = FoodText::normalize(trim($payee.' '.($note ?? '').' '.($typeText ?? '')));

        if ($text === '') {
            return false;
        }

        foreach (['egen konto', 'egne konto', 'mellom egne'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        $settlesCard = str_contains($text, 'innbetaling') || str_contains($text, 'nedbetaling');
        $mentionsCard = str_contains($text, 'kreditt')
            || str_contains($text, 'mastercard')
            || str_contains($text, 'visa');

        if ($settlesCard && $mentionsCard) {
            return true;
        }

        $type = FoodText::normalize($accountType ?? '');
        $isCreditCard = str_contains($type, 'credit') || str_contains($type, 'kreditt');

        return $isCreditCard && $settlesCard;
    }

    public function digits(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value ?? '');

        return is_string($digits) ? $digits : '';
    }
}
