<?php

namespace App\Console\Commands;

use App\Actions\RefreshHoldingPrices;
use App\Models\Holding;
use App\Models\HoldingPriceSchedule;
use App\Models\User;
use Illuminate\Console\Command;

class UpdateHoldingPrices extends Command
{
    protected $signature = 'holdings:update-prices
        {--due : Bare for brukere der et tidspunkt i kursplanen er passert og ikke kjørt}';

    protected $description = 'Hent siste kurs for beholdninger som har ticker';

    public function handle(RefreshHoldingPrices $refresh): int
    {
        $query = Holding::query()->whereNotNull('ticker')->orderBy('id');

        if ($this->option('due')) {
            $userIds = $this->claimDueSchedules();

            if ($userIds === []) {
                return self::SUCCESS;
            }

            $query->whereIn('user_id', $userIds);
        }

        $holdings = $query->get();

        if ($holdings->isEmpty()) {
            $this->info('Ingen beholdninger har ticker.');

            return self::SUCCESS;
        }

        $result = $refresh($holdings);

        foreach ($result['updated'] as $holding) {
            $this->info(sprintf('Beholdning %d (%s): kurs %s.', $holding->id, $holding->ticker, $holding->price));
        }

        foreach ($result['skipped'] as $row) {
            $this->error(sprintf('Beholdning %d: %s', $row['holding']->id, $row['message']));
        }

        $this->info(sprintf('%d oppdatert, %d hoppet over.', count($result['updated']), count($result['skipped'])));

        return $result['skipped'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The users whose price schedule has a time that has passed and has not
     * run. The time is marked as handled here, before any price is fetched, so
     * a second check in the same minute finds nothing to do.
     *
     * @return list<int>
     */
    private function claimDueSchedules(): array
    {
        User::query()
            ->whereDoesntHave('holdingPriceSchedule')
            ->get()
            ->each(fn (User $user) => HoldingPriceSchedule::for($user));

        $now = now();
        $userIds = [];

        foreach (HoldingPriceSchedule::query()->orderBy('id')->get() as $schedule) {
            $time = $schedule->dueTime($now);

            if ($time === null || ! $schedule->claim($time)) {
                continue;
            }

            $this->info(sprintf('Bruker %d: kursplanen passerte %s.', $schedule->user_id, $time->format('d.m.Y H:i')));

            $userIds[] = $schedule->user_id;
        }

        return $userIds;
    }
}
