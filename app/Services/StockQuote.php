<?php

namespace App\Services;

final readonly class StockQuote
{
    /**
     * The currency is kept the way Yahoo writes it, because the case carries
     * meaning: GBp is pence and GBP is pounds.
     */
    public function __construct(
        public float $price,
        public ?string $currency,
    ) {}
}
