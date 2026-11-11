<?php

namespace App\Services\Sparebank1;

use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;

readonly class ParsedBankTransaction
{
    /**
     * @param  list<string>  $lookupKeys
     */
    public function __construct(
        public string $externalId,
        public string $dedupeKey,
        public array $lookupKeys,
        public CarbonImmutable $bookedOn,
        public string $absoluteAmount,
        public string $payee,
        public ?string $note,
        public BookingStatus $bookingStatus,
        public ?string $remoteAccountNumber,
        public ?string $typeText,
    ) {}
}
