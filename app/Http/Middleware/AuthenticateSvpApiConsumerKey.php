<?php

namespace App\Http\Middleware;

use App\Models\SvpApiConsumerKey;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSvpApiConsumerKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) config('svp.external_api.header', 'X-Portal-API-Key');
        $presentedKey = trim((string) $request->header($header));

        if ($presentedKey === '' || strlen($presentedKey) > 128) {
            return response()->json([
                'success' => false,
                'message' => 'A valid X-Portal-API-Key header is required.',
            ], 401);
        }

        $apiKey = SvpApiConsumerKey::query()
            ->with(['session.candidate'])
            ->usable()
            ->where('key_hash', SvpApiConsumerKey::hashPlaintext($presentedKey))
            ->first();

        if (! $apiKey instanceof SvpApiConsumerKey) {
            return response()->json([
                'success' => false,
                'message' => 'The API key is invalid, revoked, expired, or mapped to an expired SVP session.',
            ], 401);
        }

        try {
            $sessionIsUsable = $apiKey->isUsable();
        } catch (DecryptException) {
            $sessionIsUsable = false;
        }
        if (! $sessionIsUsable) {
            return response()->json([
                'success' => false,
                'message' => 'The API key is invalid, revoked, expired, or mapped to an expired SVP session.',
            ], 401);
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        $apiKey->session->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('svp_api_consumer_key', $apiKey);
        $request->attributes->set('candidate_svp_session', $apiKey->session);

        return $next($request);
    }
}
