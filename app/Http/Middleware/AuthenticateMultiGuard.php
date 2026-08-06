<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMultiGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        // Admin guard first: a signed-in admin must always win over any
        // residual web-guard (agency/user) identity in the same session.
        $user = $request->user('admin') ?? $request->user('web');

        if ($user) {
            $request->setUserResolver(fn ($guard = null) => $user);

            return $next($request);
        }

        // JSON/API callers (Accept: application/json) get a 401 instead of
        // being bounced to the HTML login page.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('login');
    }
}
