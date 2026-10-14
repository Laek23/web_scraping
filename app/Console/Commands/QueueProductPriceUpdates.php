<?php

namespace App\Console\Commands;

use App\Jobs\UpdateProductPricesJob;
use App\Models\Deal;
use Illuminate\Console\Command;

class QueueProductPriceUpdates extends Command
{
    protected $signature = 'deals:queue-price-updates';

    protected $description = 'Queue a price refresh for active published offers.';

    public function handle(): int
    {
        $queued = 0;
        Deal::query()
            ->where('status', 'active')
            ->select('id')
            ->chunkById(250, function ($deals) use (&$queued) {
                foreach ($deals as $deal) {
                    UpdateProductPricesJob::dispatch($deal->id)->onQueue('scraping');
                    $queued++;
                }
            });

        $this->components->info("Queued {$queued} price update jobs.");

        return self::SUCCESS;
    }
}
