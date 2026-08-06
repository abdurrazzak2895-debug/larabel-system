<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AgencyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('admin') ?? $request->user('web');

        if ($user instanceof \App\Models\Admin) {
            return $next($request);
        }

        if ($user instanceof \App\Models\User && $user->agency_id) {
            $request->merge(['agency_id' => $user->agency_id]);

            return $next($request);
        }

        // JSON/API callers get a 403 instead of a redirect to the HTML login.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Unauthorized: no agency scope.'], 403);
        }

        return redirect()->route('login')
            ->with('status', 'Your account is not assigned to an agency. Please contact the administrator.');
    }
}
