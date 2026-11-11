<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Pending = 'pending';
    case Booked = 'booked';

    public static function fromBank(?string $status): self
    {
        return strtoupper((string) $status) === 'PENDING' ? self::Pending : self::Booked;
    }
}
