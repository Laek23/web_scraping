<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DealCommentController extends Controller
{
    public function store(Request $request, Deal $deal): JsonResponse
    {
        abort_unless($deal->status === 'active', 404);
        $validated = $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']]);
        $comment = DB::transaction(function () use ($request, $deal, $validated) {
            $comment = $request->user()->dealComments()->create([
                'deal_id' => $deal->id,
                'body' => trim($validated['body']),
            ]);
            Deal::whereKey($deal->id)->increment('comments_count');

            return $comment;
        });
        $comment->load('user:id,name,avatar_url');

        return response()->json($this->serialize($comment), 201);
    }

    public function destroy(Request $request, DealComment $comment): JsonResponse
    {
        abort_unless($comment->user_id === $request->user()->id || $comment->deal->user_id === $request->user()->id, 403);
        DB::transaction(function () use ($comment) {
            Deal::whereKey($comment->deal_id)->where('comments_count', '>', 0)->decrement('comments_count');
            $comment->delete();
        });

        return response()->json(['message' => 'Comentario eliminado.']);
    }

    private function serialize(DealComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'createdAt' => $comment->created_at->toISOString(),
            'user' => $comment->user->only(['id', 'name', 'avatar_url']),
        ];
    }
}
