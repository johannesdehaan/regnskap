<?php

namespace App\Actions;

use App\Enums\BankConnectionStatus;
use App\Exceptions\Sparebank1Exception;
use App\Models\BankConnection;
use App\Models\User;
use App\Services\Sparebank1\Sparebank1Client;
use App\Services\Sparebank1\Sparebank1TransactionParser;
use App\Support\BankMovement;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class SyncSparebank1Transactions
{
    public function __construct(
        private Sparebank1Client $client,
        private RefreshSparebank1Accounts $accounts,
        private Sparebank1TransactionParser $parser,
        private RecordSparebank1Transaction $record,
        private BankMovement $movement,
    ) {}

    /**
     * @return array{imported: int, updated: int, skipped: int}
     */
    public function __invoke(User $user): array
    {
        $connection = $user->bankConnection;

        if ($connection === null || $connection->status !== BankConnectionStatus::Connected) {
            throw new Sparebank1Exception('SpareBank 1 er ikke tilkoblet.');
        }

        try {
            ($this->accounts)($connection);
            $connection->unsetRelation('accounts');

            $enabled = $connection->accounts()->where('sync_enabled', true)->get();

            if ($enabled->isEmpty()) {
                throw new Sparebank1Exception('Velg minst én konto som skal synkes.');
            }

            $ownNumbers = [];

            foreach ($connection->accounts as $bankAccount) {
                $number = $this->movement->digits($bankAccount->account_number);

                if ($number !== '') {
                    $ownNumbers[] = $number;
                }
            }

            $today = CarbonImmutable::now('Europe/Oslo')->startOfDay();
            $from = $connection->last_synced_at instanceof DateTimeInterface
                ? CarbonImmutable::parse($connection->last_synced_at)->timezone('Europe/Oslo')->subDays(14)->toDateString()
                : $today->subDays(90)->toDateString();
            $to = $today->toDateString();

            $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
            $warnings = [];

            foreach ($enabled as $account) {
                $page = $this->fetch($connection, $account->external_key, $from, $to, 0);
                array_push($warnings, ...$this->warningText($page['errors']));

                foreach ($page['transactions'] as $row) {
                    $parsed = $this->parser->parse($account->external_key, $row);

                    if ($parsed === null) {
                        $counts['skipped']++;

                        continue;
                    }

                    $result = ($this->record)($user, $account, $parsed, $ownNumbers);
                    $counts[$result]++;
                }
            }

            $connection->forceFill([
                'last_synced_at' => now(),
                'last_sync_error' => $warnings === [] ? null : implode(' ', array_unique($warnings)),
            ])->save();

            return $counts;
        } catch (Sparebank1Exception $exception) {
            $connection->refresh();
            $connection->forceFill([
                'last_sync_error' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }
    }

    /**
     * @return array{transactions: list<array<string, mixed>>, errors: list<string>}
     */
    private function fetch(BankConnection $connection, string $accountKey, string $from, string $to, int $depth): array
    {
        $page = $this->client->transactions($connection, $accountKey, $from, $to);
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $span = (int) $start->diffInDays($end);

        if (count($page['transactions']) < 1000 || $depth >= 6 || $span < 1) {
            return $page;
        }

        $middle = $start->addDays(max(1, intdiv($span, 2)));

        if ($middle->greaterThanOrEqualTo($end)) {
            return $page;
        }

        $left = $this->fetch($connection, $accountKey, $from, $middle->toDateString(), $depth + 1);
        $right = $this->fetch($connection, $accountKey, $middle->addDay()->toDateString(), $to, $depth + 1);

        return [
            'transactions' => [...$left['transactions'], ...$right['transactions']],
            'errors' => [...$left['errors'], ...$right['errors'], ...$page['errors']],
        ];
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function warningText(array $errors): array
    {
        $messages = [];

        foreach ($errors as $error) {
            $messages[] = match ($error) {
                'CREDIT_ACCOUNTS_FAILED' => 'Noen kredittkortkontoer kunne ikke hentes.',
                'RECENT_TRANSACTIONS_FAILED' => 'Nylige transaksjoner kunne ikke hentes.',
                default => 'SpareBank 1 rapporterte en delvis feil.',
            };
        }

        return $messages;
    }
}
