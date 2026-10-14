<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->profile($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user->id)],
            'avatarUrl' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
            'preferences' => ['sometimes', 'array'],
            'preferences.currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'preferences.notifications' => ['sometimes', 'boolean'],
        ]);

        $columns = [];
        foreach (['name', 'email'] as $field) {
            if (array_key_exists($field, $data)) {
                $columns[$field] = $field === 'email' ? strtolower($data[$field]) : $data[$field];
            }
        }
        if (array_key_exists('avatarUrl', $data)) {
            $columns['avatar_url'] = $data['avatarUrl'];
        }
        if (array_key_exists('preferences', $data)) {
            $columns['preferences'] = array_replace($user->preferences ?? [], $data['preferences']);
        }

        $user->update($columns);

        return response()->json(['user' => $this->profile($request)]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'currentPassword' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);
        $user = $request->user();
        if (! Hash::check($validated['currentPassword'], $user->password)) {
            return response()->json([
                'message' => 'La contraseña actual no es correcta.',
                'errors' => ['currentPassword' => ['La contraseña actual no es correcta.']],
            ], 422);
        }

        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();

        return response()->json(['message' => 'Contraseña actualizada. Inicia sesión de nuevo.']);
    }

    private function profile(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatarUrl' => $user->avatar_url,
            'preferences' => $user->preferences ?? ['currency' => 'USD', 'notifications' => true],
            'dealsCount' => $user->deals()->count(),
            'bookmarksCount' => $user->bookmarks()->count(),
        ];
    }
}
