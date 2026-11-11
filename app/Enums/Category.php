<?php

namespace App\Enums;

enum Category: string
{
    case O = 'O';
    case Dagligvarer = 'Dagligvarer';
    case Servering = 'Servering';
    case BollerOgBrus = 'Boller og brus';
    case TingTilHjemmet = 'Ting til hjemmet';
    case Bokostnader = 'Bokostnader';
    case Trening = 'Trening';
    case Sykkel = 'Sykkel';
    case Bil = 'Bil';
    case OffentligTransport = 'Offentlig transport';
    case OpplevelserReiser = 'Opplevelser, reiser';
    case Abonnement = 'Abonnement';
    case Klaer = 'Klær';
    case Elektronikk = 'Elektronikk';
    case Utdanning = 'Utdanning';
    case Dannelse = 'Dannelse';
    case Tjenester = 'Tjenester';
    case Annet = 'Annet';
    case Tiende = 'Tiende';
    case ReligioseGjenstander = 'Religiøse gjenstander';
    case GaverOgDonasjoner = 'Gaver og donasjoner';

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

        return null;
    }

    public function countsAsFood(): bool
    {
        return match ($this) {
            self::Dagligvarer, self::BollerOgBrus, self::Servering => true,
            default => false,
        };
    }
}
