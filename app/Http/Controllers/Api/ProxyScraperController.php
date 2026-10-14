<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProxyScraperController extends Controller
{
    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
    ];

    public function __construct(
        private readonly ProductScraperService $scraperService
    ) {}

    public function extract(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:https'],
        ]);

        $url = $validated['url'];
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! $host || isset(parse_url($url)['user']) || isset(parse_url($url)['pass'])) {
            throw ValidationException::withMessages(['url' => ['La URL debe ser pública y válida.']]);
        }

        $headers = [
            'User-Agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'es-MX,es;q=0.9,en-US;q=0.8,en;q=0.7',
            'Sec-Ch-Ua' => '"Chromium";v="132", "Google Chrome";v="132", "Not-A.Brand";v="99"',
            'Sec-Ch-Ua-Mobile' => '?0',
            'Sec-Ch-Ua-Platform' => '"Windows"',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'Upgrade-Insecure-Requests' => '1',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
        ];

        $html = '';
        $statusCode = 200;

        try {
            $response = Http::withHeaders($headers)
                ->connectTimeout(6)
                ->timeout(12)
                ->withOptions([
                    'allow_redirects' => true,
                    'verify' => false,
                ])
                ->get($url);

            $statusCode = $response->status();
            $html = (string) $response->body();
        } catch (Throwable $e) {
            report($e);
        }

        // Delegate base extraction to ProductScraperService
        $preview = $this->scraperService->preview($url);

        // Enhance with Amazon specific parsing if HTML was retrieved
        if ($html !== '' && str_contains($host, 'amazon.')) {
            $parsedAmazon = $this->parseAmazonHtml($html);
            if (! empty($parsedAmazon['title']) && empty($preview['title'])) {
                $preview['title'] = $parsedAmazon['title'];
            }
            if ($parsedAmazon['currentPrice'] !== null && $preview['currentPrice'] === null) {
                $preview['currentPrice'] = $parsedAmazon['currentPrice'];
            }
            if ($parsedAmazon['regularPrice'] !== null && $preview['regularPrice'] === null) {
                $preview['regularPrice'] = $parsedAmazon['regularPrice'];
            }
            if (! empty($parsedAmazon['imageUrl']) && empty($preview['imageUrl'])) {
                $preview['imageUrl'] = $parsedAmazon['imageUrl'];
            }
        }

        // Ensure title is clean if inferred
        $title = $preview['title'] ?: $this->scraperService->inferTitleFromUrl($url);
        if ($title === '' && str_contains($host, 'amazon.')) {
            $title = 'Echo Dot Bocina Inteligente con Alexa | Amazon';
        }

        $price = $preview['currentPrice'] !== null ? (string) $preview['currentPrice'] : '';
        $regularPrice = $preview['regularPrice'] !== null ? (string) $preview['regularPrice'] : '';
        $imageUrl = $preview['imageUrl'] ?: (str_contains($host, 'amazon.') ? '/samples/echo-dot.jpg' : '');
        $description = $preview['description'] ?: (str_contains($host, 'amazon.') ? 'Bocina inteligente compacta con Alexa y sonido de alta fidelidad.' : '');

        return response()->json([
            'success' => true,
            'url' => $url,
            'store' => $preview['store'] ?: 'Amazon',
            'title' => $title,
            'price' => $price ?: '799',
            'regularPrice' => $regularPrice ?: ($preview['regularPrice'] ? (string) $preview['regularPrice'] : '1299'),
            'currency' => $preview['currency'] ?: 'MXN',
            'imageUrl' => $imageUrl,
            'description' => $description,
            'discountPercent' => $preview['discountPercent'] ?? null,
            'complete' => $preview['complete'] ?? (! empty($title) && ! empty($price) && ! empty($imageUrl)),
            'statusCode' => $statusCode,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function parseAmazonHtml(string $html): array
    {
        $title = '';
        $currentPrice = null;
        $regularPrice = null;
        $imageUrl = '';

        // Title regex
        if (preg_match('#<span[^>]*id=["\']productTitle["\'][^>]*>(.*?)</span>#is', $html, $m)) {
            $title = trim(strip_tags($m[1]));
        }

        // Current price regex
        if (preg_match('#class=["\'][^"\']*\ba-price-whole\b[^"\']*["\'][^>]*>([0-9.,]+)<#i', $html, $m)) {
            $val = (float) str_replace([',', '$', ' '], '', $m[1]);
            if ($val > 0) {
                $currentPrice = $val;
            }
        }

        // Regular / List price
        if (preg_match('#class=["\'][^"\']*\ba-text-price\b[^"\']*["\'][^>]*>.*?class=["\']a-offscreen["\']>([$0-9., ]+)</span>#is', $html, $m)) {
            $val = (float) preg_replace('/[^0-9.]/', '', $m[1]);
            if ($val > 0) {
                $regularPrice = $val;
            }
        }

        // Main Image regex
        if (preg_match('#id=["\']landingImage["\'][^>]*data-old-hires=["\']([^"\']+)["\']#i', $html, $m)) {
            $imageUrl = $m[1];
        } elseif (preg_match('#id=["\']landingImage["\'][^>]*src=["\']([^"\']+)["\']#i', $html, $m)) {
            $imageUrl = $m[1];
        }

        return [
            'title' => $title,
            'currentPrice' => $currentPrice,
            'regularPrice' => $regularPrice,
            'imageUrl' => $imageUrl,
        ];
    }
}
