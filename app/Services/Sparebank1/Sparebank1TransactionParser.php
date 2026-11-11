<?php

namespace App\Services\Sparebank1;

use App\Enums\BookingStatus;
use App\Support\BankMovement;
use App\Support\FoodText;
use Carbon\CarbonImmutable;

/**
 * Den krypterte transaksjons-id-en fra SpareBank 1 er ikke stabil mellom økter.
 * nonUniqueId kan ikke brukes alene, så den lagres sammen med kontonøkkelen.
 * Uten den brukes et fingeravtrykk av konto, dato, beløp, tekst og motkonto.
 */
class Sparebank1TransactionParser
{
    public function __construct(private BankMovement $movement) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public function parse(string $accountKey, array $row): ?ParsedBankTransaction
    {
        $bookedOn = $this->bookedOn($row['date'] ?? $row['interestDate'] ?? null);
        $signed = $this->signed($row['amount'] ?? null);

        if ($bookedOn === null || $signed === null) {
            return null;
        }

        $description = $this->text($row['description'] ?? null);
        $cleaned = $this->text($row['cleanedDescription'] ?? null);
        $remoteName = $this->text($row['remoteAccountName'] ?? null);
        $merchant = $this->merchantName($row['merchant'] ?? null);
        $payee = $merchant ?? $cleaned ?? $description ?? $remoteName ?? 'Ukjent';
        $payee = mb_substr($payee, 0, 255);
        $typeText = $this->text($row['typeText'] ?? null);
        $remote = $this->text($row['remoteAccountNumber'] ?? null);
        $remoteDigits = $this->movement->digits($remote);
        $note = $this->note($payee, $description, $this->text($row['kidOrMessage'] ?? null), $typeText);
        $nonUnique = $this->text($row['nonUniqueId'] ?? null);
        $fingerprint = $this->hash('fp', [
            $accountKey,
            $bookedOn->toDateString(),
            $signed,
            FoodText::normalize($cleaned ?? $description ?? $payee),
            $remoteDigits,
        ]);
        $lookup = [$fingerprint];

        if ($nonUnique !== null) {
            array_unshift($lookup, $this->hash('nu', [$accountKey, $nonUnique]));
        }

        $bankId = $this->text($row['id'] ?? null);

        return new ParsedBankTransaction(
            externalId: mb_substr($bankId ?? 'fp:'.$fingerprint, 0, 512),
            dedupeKey: $lookup[0],
            lookupKeys: $lookup,
            bookedOn: $bookedOn,
            absoluteAmount: number_format(abs((float) $signed), 2, '.', ''),
            payee: $payee,
            note: $note,
            bookingStatus: BookingStatus::fromBank($this->text($row['bookingStatus'] ?? null)),
            remoteAccountNumber: $remoteDigits !== '' ? $remoteDigits : null,
            typeText: $typeText,
        );
    }

    /**
     * @param  list<string>  $parts
     */
    private function hash(string $prefix, array $parts): string
    {
        return hash('sha256', $prefix.'|'.implode('|', $parts));
    }

    private function bookedOn(mixed $value): ?CarbonImmutable
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $number = (int) $value;
            $moment = $number > 10_000_000_000
                ? CarbonImmutable::createFromTimestampMs($number)
                : CarbonImmutable::createFromTimestamp($number);

            return $moment->timezone('Europe/Oslo')->startOfDay();
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1) {
            return CarbonImmutable::parse($value, 'Europe/Oslo')->startOfDay();
        }

        return null;
    }

    private function signed(mixed $amount): ?string
    {
        if (is_int($amount) || is_float($amount) || (is_string($amount) && is_numeric($amount))) {
            return number_format((float) $amount, 2, '.', '');
        }

        return null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function merchantName(mixed $merchant): ?string
    {
        if (! is_array($merchant)) {
            return null;
        }

        return $this->text($merchant['name'] ?? null);
    }

    private function note(?string $payee, ?string $description, ?string $message, ?string $typeText): ?string
    {
        $parts = [];

        foreach ([$description, $message, $typeText] as $part) {
            if ($part !== null && $part !== $payee && ! in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }
}
