<?php

namespace App\Console\Commands;

use App\Actions\SyncSparebank1Transactions;
use App\Enums\BankConnectionStatus;
use App\Exceptions\Sparebank1Exception;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SyncSparebank1 extends Command
{
    protected $signature = 'sparebank1:sync {--user= : Begrens synken til én bruker-id}';

    protected $description = 'Hent nye transaksjoner fra SpareBank 1';

    public function handle(SyncSparebank1Transactions $sync): int
    {
        $userId = $this->option('user');

        $users = User::query()
            ->whereHas('bankConnection', function (Builder $query): void {
                $query->where('status', BankConnectionStatus::Connected->value);
            })
            ->when(is_string($userId) && $userId !== '', fn (Builder $query) => $query->whereKey($userId))
            ->get();

        if ($users->isEmpty()) {
            $this->info('Ingen tilkoblede SpareBank 1-brukere.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($users as $user) {
            try {
                $counts = $sync($user);
                $this->info(sprintf(
                    'Bruker %d: %d nye, %d oppdatert, %d hoppet over.',
                    $user->id,
                    $counts['imported'],
                    $counts['updated'],
                    $counts['skipped'],
                ));
            } catch (Sparebank1Exception $exception) {
                $failed = true;
                $this->error('Bruker '.$user->id.': '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
