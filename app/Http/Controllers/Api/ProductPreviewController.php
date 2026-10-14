<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProductPreviewController extends Controller
{
    public function __invoke(Request $request, ProductScraperService $scraper): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:https'],
        ]);
        $host = strtolower((string) parse_url($validated['url'], PHP_URL_HOST));
        if (! $host || isset(parse_url($validated['url'])['user']) || isset(parse_url($validated['url'])['pass'])) {
            throw ValidationException::withMessages(['url' => ['La URL debe ser pública y no contener credenciales.']]);
        }

        $preview = $scraper->preview($validated['url']);

        return response()->json($preview, 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
