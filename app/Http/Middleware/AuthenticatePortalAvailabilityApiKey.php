<?php

namespace App\Http\Middleware;

use App\Models\PortalAvailabilityApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticatePortalAvailabilityApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('portal.external_api.header', 'X-Portal-API-Key');
        $presentedKey = trim((string) $request->header($header));

        if ($presentedKey === '' || strlen($presentedKey) > 128) {
            return response()->json([
                'success' => false,
                'message' => 'A valid X-Portal-API-Key header is required.',
            ], 401);
        }

        $apiKey = PortalAvailabilityApiKey::query()
            ->with('credential')
            ->usable()
            ->where('key_hash', PortalAvailabilityApiKey::hashPlaintext($presentedKey))
            ->first();

        if (! $apiKey instanceof PortalAvailabilityApiKey || ! $apiKey->isUsable()) {
            return response()->json([
                'success' => false,
                'message' => 'The API key is invalid, revoked, expired, or not mapped to a usable portal session.',
            ], 401);
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('portal_availability_api_key', $apiKey);
        $request->attributes->set('portal_availability_credential', $apiKey->credential);

        return $next($request);
    }
}
