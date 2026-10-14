<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealBookmarkController extends Controller
{
    public function index(Request $request, DealController $deals): JsonResponse
    {
        $bookmarks = $request->user()->bookmarks()
            ->with('user:id,name,avatar_url')
            ->withCount(['bookmarks', 'comments', 'votes'])
            ->latest('deal_bookmarks.created_at')
            ->paginate(60);

        return response()->json([
            'data' => $bookmarks->getCollection()->map(fn (Deal $deal) => $deals->serialize($deal)),
            'currentPage' => $bookmarks->currentPage(),
            'lastPage' => $bookmarks->lastPage(),
            'total' => $bookmarks->total(),
        ]);
    }

    public function toggle(Request $request, Deal $deal): JsonResponse
    {
        abort_unless($deal->status === 'active', 404);
        $user = $request->user();
        $result = DB::transaction(function () use ($deal, $user) {
            $lockedDeal = Deal::query()->lockForUpdate()->findOrFail($deal->id);
            $existing = $user->bookmarks()->whereKey($lockedDeal->id)->exists();
            if ($existing) {
                $user->bookmarks()->detach($lockedDeal->id);
                $bookmarked = false;
            } else {
                $user->bookmarks()->syncWithoutDetaching([$lockedDeal->id]);
                $bookmarked = true;
            }
            $lockedDeal->bookmarks_count = max(0, $lockedDeal->bookmarks_count + ($bookmarked ? 1 : -1));
            $lockedDeal->save();

            return ['bookmarked' => $bookmarked, 'bookmarksCount' => $lockedDeal->bookmarks_count];
        });

        return response()->json($result);
    }
}
