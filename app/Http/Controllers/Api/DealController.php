<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\PriceHistory;
use App\Services\ProductScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $deals = Deal::query()
            ->where('status', 'active')
            ->with(['user:id,name,avatar_url'])
            ->withCount(['bookmarks', 'comments', 'votes'])
            ->when($request->filled('store'), fn ($query) => $query->where('store', $request->string('store')))
            ->when($request->filled('minDiscount'), fn ($query) => $query->where('discount_percent', '>=', min(100, max(0, (int) $request->input('minDiscount')))))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = addcslashes($request->string('q')->toString(), '%_');
                $query->where(fn ($search) => $search
                    ->where('title', 'like', "%{$term}%")
                    ->orWhere('store', 'like', "%{$term}%"));
            })
            ->orderByDesc('votes_count')
            ->latest()
            ->paginate(min(60, max(1, (int) $request->input('perPage', 24))));

        if ($request->user()) {
            $deals->getCollection()->each(function (Deal $deal) use ($request) {
                $deal->is_bookmarked = $deal->bookmarks()
                    ->where('users.id', $request->user()->id)
                    ->exists();
            });
        }

        return response()->json([
            'data' => $deals->getCollection()->map(fn (Deal $deal) => $this->serialize($deal)),
            'currentPage' => $deals->currentPage(),
            'lastPage' => $deals->lastPage(),
            'total' => $deals->total(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $deal = $request->user()->deals()->create($this->validatedData($request));
        if ($deal->current_price !== null) {
            $deal->priceHistories()->create([
                'price' => $deal->current_price,
                'currency' => $deal->currency,
                'recorded_at' => now(),
            ]);
        }

        return response()->json($this->serialize($deal->load('user')->loadCount(['bookmarks', 'comments', 'votes'])), 201);
    }

    public function show(Request $request, Deal $deal): JsonResponse
    {
        abort_unless($deal->status === 'active' || $request->user()?->id === $deal->user_id, 404);
        $deal->load([
            'user:id,name,avatar_url',
            'comments' => fn ($query) => $query->with('user:id,name,avatar_url')->latest()->limit(100),
            'priceHistories' => fn ($query) => $query->orderBy('recorded_at')->limit(365),
        ])->loadCount(['bookmarks', 'comments', 'votes']);
        if ($request->user()) {
            $deal->loadExists([
                'bookmarks as is_bookmarked' => fn ($query) => $query->where('users.id', $request->user()->id),
            ]);
        }

        return response()->json([
            'deal' => $this->serialize($deal),
            'priceHistory' => $deal->priceHistories->map(fn (PriceHistory $point) => [
                'price' => (float) $point->price,
                'currency' => $point->currency,
                'recordedAt' => $point->recorded_at->toISOString(),
            ]),
            'comments' => $deal->comments->map(fn ($comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'createdAt' => $comment->created_at->toISOString(),
                'user' => $comment->user->only(['id', 'name', 'avatar_url']),
            ]),
        ]);
    }

    public function update(Request $request, Deal $deal): JsonResponse
    {
        $deal = $request->user()->deals()->findOrFail($deal->id);
        $deal->update($this->validatedData($request, true));
        if ($deal->wasChanged('current_price') && $deal->current_price !== null) {
            $deal->priceHistories()->create([
                'price' => $deal->current_price,
                'currency' => $deal->currency,
                'recorded_at' => now(),
            ]);
        }

        return response()->json($this->serialize($deal->load('user')->loadCount(['bookmarks', 'comments', 'votes'])));
    }

    public function destroy(Request $request, Deal $deal): JsonResponse
    {
        $request->user()->deals()->findOrFail($deal->id)->delete();

        return response()->json(['message' => 'Oferta eliminada.']);
    }

    public function userDeals(Request $request): JsonResponse
    {
        $deals = $request->user()->deals()
            ->with('user:id,name,avatar_url')
            ->withCount(['bookmarks', 'comments', 'votes'])
            ->latest()
            ->paginate(60);

        return response()->json([
            'data' => $deals->getCollection()->map(fn (Deal $deal) => $this->serialize($deal)),
            'currentPage' => $deals->currentPage(),
            'lastPage' => $deals->lastPage(),
            'total' => $deals->total(),
        ]);
    }

    public function updateUserDealStatus(Request $request, int $deal): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', Rule::in(['active', 'sold_out', 'expired'])]]);
        $offer = $request->user()->deals()->findOrFail($deal);
        $offer->update(['status' => $validated['status']]);

        return response()->json($this->serialize($offer->load('user')->loadCount(['bookmarks', 'comments', 'votes'])));
    }

    public function checkPrice(Request $request, Deal $deal, ProductScraperService $scraper): JsonResponse
    {
        $startTime = microtime(true);
        $preview = $scraper->preview($deal->product_url);
        $latencyMs = (int) round((microtime(true) - $startTime) * 1000);

        $oldPrice = $deal->current_price !== null ? (float) $deal->current_price : null;
        $priceChanged = false;
        $statusMessage = 'El precio se mantiene en '.($deal->currency ?? 'USD').' '.number_format((float) ($deal->current_price ?? 0), 2);

        if ($preview['currentPrice'] !== null) {
            $newPrice = (float) $preview['currentPrice'];
            $formattedPrice = number_format($newPrice, 2, '.', '');

            if ($oldPrice === null || abs($oldPrice - $newPrice) >= 0.01) {
                $priceChanged = true;
                if ($oldPrice !== null && $newPrice < $oldPrice) {
                    $diff = $oldPrice - $newPrice;
                    $statusMessage = '¡Bajada de precio detectada! Ahorras '.($deal->currency ?? 'USD').' '.number_format($diff, 2);
                } elseif ($oldPrice !== null && $newPrice > $oldPrice) {
                    $diff = $newPrice - $oldPrice;
                    $statusMessage = 'El precio subió '.($deal->currency ?? 'USD').' '.number_format($diff, 2);
                } else {
                    $statusMessage = 'Precio registrado: '.($deal->currency ?? 'USD').' '.$formattedPrice;
                }

                $deal->priceHistories()->create([
                    'price' => $formattedPrice,
                    'currency' => $preview['currency'] ?? $deal->currency,
                    'recorded_at' => now(),
                ]);
            }

            $deal->update([
                'current_price' => $formattedPrice,
                'regular_price' => $preview['regularPrice'] ?? $deal->regular_price,
                'discount_percent' => $preview['discountPercent'] ?? $deal->discount_percent,
                'image_url' => $preview['imageUrl'] ?: $deal->image_url,
                'title' => $preview['title'] ?: $deal->title,
                'currency' => $preview['currency'] ?: $deal->currency,
            ]);
        } else {
            $statusMessage = 'Comprobación completada. Se mantiene el precio actual.';
        }

        $deal->load([
            'user:id,name,avatar_url',
            'comments' => fn ($query) => $query->with('user:id,name,avatar_url')->latest()->limit(100),
            'priceHistories' => fn ($query) => $query->orderBy('recorded_at')->limit(365),
        ])->loadCount(['bookmarks', 'comments', 'votes']);

        return response()->json([
            'deal' => $this->serialize($deal),
            'priceHistory' => $deal->priceHistories->map(fn (PriceHistory $point) => [
                'price' => (float) $point->price,
                'currency' => $point->currency,
                'recordedAt' => $point->recorded_at->toISOString(),
            ]),
            'comments' => $deal->comments->map(fn ($comment) => [
                'id' => $comment->id,
                'body' => $comment->body,
                'createdAt' => $comment->created_at->toISOString(),
                'user' => $comment->user->only(['id', 'name', 'avatar_url']),
            ]),
            'run' => [
                'checkedAt' => now()->toISOString(),
                'latencyMs' => $latencyMs,
                'status' => $statusMessage,
                'price' => (float) ($deal->current_price ?? 0),
                'priceChanged' => $priceChanged,
            ],
        ]);
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $validated = $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'productUrl' => [$required, 'url:https', 'max:2048'],
            'imageUrl' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'galleryImages' => ['sometimes', 'array', 'max:16'],
            'galleryImages.*' => ['required', 'url:https', 'max:2048'],
            'store' => [$required, 'string', 'max:100'],
            'productDetails' => ['sometimes', 'array', 'max:30'],
            'productDetails.*' => ['string', 'max:500'],
            'currentPrice' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'regularPrice' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'discountPercent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'sold_out', 'expired'])],
        ]);

        $data = [];
        foreach ([
            'title' => 'title',
            'description' => 'description',
            'productUrl' => 'product_url',
            'imageUrl' => 'image_url',
            'galleryImages' => 'gallery_images',
            'store' => 'store',
            'productDetails' => 'product_details',
            'currentPrice' => 'current_price',
            'regularPrice' => 'regular_price',
            'currency' => 'currency',
            'discountPercent' => 'discount_percent',
            'status' => 'status',
        ] as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $data[$column] = $validated[$input];
            }
        }

        if (! $partial) {
            $data['currency'] ??= 'USD';
        }

        return $data;
    }

    public function serialize(Deal $deal): array
    {
        $discount = $deal->discount_percent;
        if ($deal->current_price !== null && $deal->regular_price > 0 && $deal->current_price < $deal->regular_price) {
            $discount = (int) round((1 - $deal->current_price / $deal->regular_price) * 100);
        }

        return [
            'id' => (string) $deal->id,
            'userId' => (string) $deal->user_id,
            'title' => $deal->title,
            'description' => $deal->description ?? '',
            'productUrl' => $deal->product_url,
            'imageUrl' => $deal->image_url ?? '',
            'galleryImages' => $this->galleryImages($deal),
            'store' => $deal->store,
            'productDetails' => $deal->product_details ?? [],
            'currentPrice' => $deal->current_price !== null ? (float) $deal->current_price : null,
            'regularPrice' => $deal->regular_price !== null ? (float) $deal->regular_price : null,
            'currency' => $deal->currency,
            'discountPercent' => $discount,
            'status' => $deal->status,
            'votesCount' => (int) ($deal->votes_count ?? 0),
            'bookmarksCount' => (int) ($deal->bookmarks_count ?? 0),
            'commentsCount' => (int) ($deal->comments_count ?? 0),
            'isBookmarked' => (bool) ($deal->is_bookmarked ?? false),
            'createdAt' => $deal->created_at->toISOString(),
            'user' => $deal->relationLoaded('user') && $deal->user
                ? $deal->user->only(['id', 'name', 'avatar_url'])
                : null,
        ];
    }

    private function galleryImages(Deal $deal): array
    {
        $images = array_values(array_unique(array_filter(
            $deal->gallery_images ?? [],
            fn ($url) => is_string($url) && $url !== '',
        )));
        $primary = $deal->image_url ?? '';
        if ($primary !== '' && ! in_array($primary, $images, true)) {
            array_unshift($images, $primary);
        }

        return array_slice($images, 0, 16);
    }
}
