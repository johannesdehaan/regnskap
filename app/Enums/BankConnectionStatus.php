<?php

namespace App\Enums;

enum BankConnectionStatus: string
{
    case Connected = 'connected';
    case NeedsReauth = 'needs_reauth';
    case Disconnected = 'disconnected';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Tilkoblet',
            self::NeedsReauth => 'Må logge inn med BankID på nytt',
            self::Disconnected => 'Ikke tilkoblet',
        };
    }
}
