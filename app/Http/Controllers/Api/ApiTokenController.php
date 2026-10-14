<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->tokens()
                ->latest()
                ->get(['id', 'name', 'abilities', 'last_used_at', 'created_at'])
                ->map(fn ($token) => [
                    'id' => (string) $token->id,
                    'name' => $token->name,
                    'lastUsedAt' => $token->last_used_at?->toISOString(),
                    'createdAt' => $token->created_at->toISOString(),
                ]),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);
        $created = $request->user()->createToken($validated['name']);

        return response()->json([
            'token' => [
                'id' => (string) $created->accessToken->id,
                'name' => $created->accessToken->name,
                'createdAt' => $created->accessToken->created_at->toISOString(),
            ],
            'plainTextToken' => $created->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();
        abort_unless($deleted, 404, 'La clave API no existe.');

        return response()->json(['message' => 'Clave API revocada correctamente.']);
    }
}
