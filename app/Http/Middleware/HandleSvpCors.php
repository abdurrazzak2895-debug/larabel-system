<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class HandleSvpCors
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->getMethod() === 'OPTIONS') {
            $headers = [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, X-Tenant-Name, Content-Type, Cache-Control, Pragma',
            ];

            return response()->json('OK', 204, $headers);
        }

        $response = $next($request);

        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, X-Tenant-Name, Content-Type, Cache-Control, Pragma');

        return $response;
    }
}
