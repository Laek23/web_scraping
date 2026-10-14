<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        $user = User::create([
            'name' => $validated['name'] ?? str($validated['email'])->before('@')->limit(255, ''),
            'email' => strtolower($validated['email']),
            'password' => $validated['password'],
        ]);

        return $this->authenticatedResponse($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Correo o contraseña incorrectos.'],
            ]);
        }

        return $this->authenticatedResponse($user);
    }

    public function demo(): JsonResponse
    {
        abort_unless(App::environment(['local', 'testing']), 404);

        $user = User::firstOrCreate(
            ['email' => 'demo@maxun.dev'],
            ['name' => 'Alex Morgan', 'password' => 'maxun-demo'],
        );

        return $this->authenticatedResponse($user);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $currentToken = $request->user()->currentAccessToken();
        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        } elseif (preg_match('/^(\d+)\|/', (string) $request->bearerToken(), $matches)) {
            $request->user()->tokens()->whereKey($matches[1])->delete();
        }

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    private function authenticatedResponse(User $user, int $status = 200): JsonResponse
    {
        return response()->json([
            'user' => $user->only(['id', 'name', 'email']),
            'token' => $user->createToken('maxun-web')->plainTextToken,
        ], $status);
    }
}
