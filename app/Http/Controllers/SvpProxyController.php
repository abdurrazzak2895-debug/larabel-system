<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use GuzzleHttp\Client;

class SvpProxyController extends Controller
{
    public function proxy(Request $request, string $any = ''): IlluminateResponse
    {
        $client = new Client(['base_uri' => 'https://svp-international-api.pacc.sa']);

        $method = $request->method();
        $upstreamPath = '/api/v1/' . ltrim($any, '/');

        $headers = [
            'Accept' => $request->header('Accept', 'application/json'),
            'X-Tenant-Name' => $request->header('X-Tenant-Name', config('svp.tenant_name')),
        ];

        if ($request->bearerToken()) {
            $headers['Authorization'] = 'Bearer ' . $request->bearerToken();
        } elseif ($request->header('Authorization')) {
            $headers['Authorization'] = $request->header('Authorization');
        }

        $options = [
            'headers' => $headers,
            'http_errors' => false,
            'verify' => true,
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $options['body'] = $request->getContent();
        } else {
            $options['query'] = $request->query();
        }

        try {
            $res = $client->request($method, $upstreamPath, $options);

            $body = $res->getBody()->getContents();
            $status = $res->getStatusCode();

            $response = response($body, $status);

            foreach ($res->getHeaders() as $name => $values) {
                $nameLower = strtolower($name);
                if (in_array($nameLower, ['transfer-encoding', 'content-encoding', 'connection'])) {
                    continue;
                }
                $response->header($name, implode(',', $values));
            }

            // CORS for browser clients
            $response->header('Access-Control-Allow-Origin', '*');
            $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Authorization, X-Tenant-Name, Content-Type, Cache-Control, Pragma');

            return $response;
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Upstream request failed', 'details' => $e->getMessage()], 502);
        }
    }
}
