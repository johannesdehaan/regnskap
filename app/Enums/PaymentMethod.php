<?php

namespace App\Enums;

enum PaymentMethod: string
{
    /**
     * What the two Brukskonto accounts were both called before they got
     * separate names. Bank text and old ledger files still say just this.
     */
    public const SHARED_BRUKSKONTO_NAME = 'Brukskonto';

    case Kredittkort = 'Kredittkort';
    case BrukskontoHegra = 'Brukskonto Hegra';
    case BrukskontoSmn = 'Brukskonto SMN';
    case LonnskontoSparebank1 = 'Lønnskonto Sparebank 1';
    case FakturakontoNordea = 'Fakturakonto Nordea';
    case HovedkontoNordea = 'Hovedkonto Nordea';
    case Wise = 'Wise';
    case DebetkortNordea = 'Debetkort Nordea';
    case SparekontoHandelsbanken = 'Sparekonto Handelsbanken';
    case RegningskontoHandelsbanken = 'Regningskonto Handelsbanken';
    case FysiskeKontanter = 'Fysiske kontanter';

    /**
     * A label typed by hand, as in a ledger file. A bare "Brukskonto" is the
     * Hegra account: that is the one entered by hand, and what the name meant
     * before the SMN account was synced.
     */
    public static function tryFromLabel(?string $label): ?self
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);

        if ($label === '') {
            return null;
        }

        $direct = self::tryFrom($label);

        if ($direct instanceof self) {
            return $direct;
        }

        foreach (self::cases() as $case) {
            if (mb_strtolower($case->value) === mb_strtolower($label)) {
                return $case;
            }
        }

        if (mb_strtolower($label) === mb_strtolower(self::SHARED_BRUKSKONTO_NAME)) {
            return self::BrukskontoHegra;
        }

        return null;
    }
}
