<?php

namespace App\Actions;

use App\Models\BankConnection;
use App\Services\Sparebank1\Sparebank1Client;

class RefreshSparebank1Accounts
{
    public function __construct(private Sparebank1Client $client) {}

    public function __invoke(BankConnection $connection): int
    {
        $saved = 0;

        foreach ($this->client->accounts($connection) as $row) {
            $key = $row['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $type = $row['type'] ?? $row['productType'] ?? null;
            $currency = strtoupper(is_string($row['currencyCode'] ?? null) ? $row['currencyCode'] : 'NOK');

            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $currency = 'NOK';
            }

            $account = $connection->accounts()->firstOrNew([
                'external_key' => mb_substr($key, 0, 255),
            ]);

            $account->user_id = $connection->user_id;
            $account->name = mb_substr($this->name($row['name'] ?? null), 0, 255);
            $account->account_number = $this->accountNumber($row['accountNumber'] ?? null);
            $account->account_type = is_string($type) ? mb_substr($type, 0, 100) : null;
            $account->currency_code = $currency;
            $account->save();
            $saved++;
        }

        return $saved;
    }

    private function name(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return 'Konto';
    }

    private function accountNumber(mixed $value): ?string
    {
        if (is_array($value)) {
            return $this->accountNumber($value['unformatted'] ?? $value['value'] ?? $value['formatted'] ?? null);
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        return is_string($digits) && $digits !== '' ? $digits : null;
    }
}
