<?php

namespace App\Jobs;

use App\Models\Deal;
use App\Models\PriceHistory;
use App\Services\ProductScraperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class UpdateProductPricesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $dealId) {}

    public function uniqueId(): string
    {
        return (string) $this->dealId;
    }

    public function handle(ProductScraperService $scraper): void
    {
        $deal = Deal::query()->whereKey($this->dealId)->where('status', 'active')->first();
        if (! $deal) {
            return;
        }

        $preview = $scraper->preview($deal->product_url);
        if ($preview['currentPrice'] === null) {
            return;
        }

        $newPrice = number_format($preview['currentPrice'], 2, '.', '');
        $lastPrice = PriceHistory::query()
            ->where('deal_id', $deal->id)
            ->latest('recorded_at')
            ->value('price');
        if ($lastPrice === null || (float) $lastPrice !== (float) $newPrice) {
            $deal->priceHistories()->create([
                'price' => $newPrice,
                'currency' => $preview['currency'],
                'recorded_at' => now(),
            ]);
        }

        $deal->update([
            'current_price' => $newPrice,
            'regular_price' => $preview['regularPrice'],
            'discount_percent' => $preview['discountPercent'],
            'image_url' => $preview['imageUrl'] ?: $deal->image_url,
            'title' => $preview['title'] ?: $deal->title,
            'currency' => $preview['currency'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
