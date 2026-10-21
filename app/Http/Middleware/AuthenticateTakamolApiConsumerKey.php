<?php

namespace App\Http\Middleware;

use App\Models\TakamolApiConsumerKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateTakamolApiConsumerKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('takamol_api.header', 'X-Takamol-API-Key');
        $presentedKey = trim((string) $request->header($header));

        if ($presentedKey === '' || strlen($presentedKey) > 128) {
            return response()->json([
                'success' => false,
                'message' => "A valid {$header} header is required.",
            ], 401);
        }

        $apiKey = TakamolApiConsumerKey::query()
            ->usable()
            ->where('key_hash', TakamolApiConsumerKey::hashPlaintext($presentedKey))
            ->first();

        if (! $apiKey instanceof TakamolApiConsumerKey || ! $apiKey->isUsable()) {
            return response()->json([
                'success' => false,
                'message' => 'The Takamol API key is invalid, revoked, or expired.',
            ], 401);
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('takamol_api_consumer_key', $apiKey);

        return $next($request);
    }
}
