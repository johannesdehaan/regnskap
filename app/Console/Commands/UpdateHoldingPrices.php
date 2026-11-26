<?php

namespace App\Console\Commands;

use App\Actions\RefreshHoldingPrices;
use App\Models\Holding;
use Illuminate\Console\Command;

class UpdateHoldingPrices extends Command
{
    protected $signature = 'holdings:update-prices';

    protected $description = 'Hent siste kurs for beholdninger som har ticker';

    public function handle(RefreshHoldingPrices $refresh): int
    {
        $holdings = Holding::query()->whereNotNull('ticker')->orderBy('id')->get();

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
}
