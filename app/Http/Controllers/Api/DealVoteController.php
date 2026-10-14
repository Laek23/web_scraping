<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealVoteController extends Controller
{
    public function toggle(Request $request, Deal $deal): JsonResponse
    {
        abort_unless($deal->status === 'active', 404);
        $user = $request->user();
        $result = DB::transaction(function () use ($deal, $user) {
            $lockedDeal = Deal::query()->lockForUpdate()->findOrFail($deal->id);
            $vote = $user->dealVotes()->where('deal_id', $lockedDeal->id)->first();
            if ($vote) {
                $vote->delete();
                $voted = false;
            } else {
                $user->dealVotes()->create(['deal_id' => $lockedDeal->id, 'value' => 1]);
                $voted = true;
            }
            $votesCount = $lockedDeal->votes()->count();
            $lockedDeal->update(['votes_count' => $votesCount]);

            return ['voted' => $voted, 'votesCount' => $votesCount];
        });

        return response()->json($result);
    }
}
