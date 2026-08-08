<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    /**
     * Regression: behind Railway's TLS-terminating proxy the forwarded scheme
     * must be honoured, otherwise the login form action and nav links resolve
     * to http:// — mixed content that browsers block.
     */
    public function test_forwarded_https_proto_produces_secure_form_action(): void
    {
        // Dispatch through the real kernel (global TrustProxies middleware) with
        // the same headers Railway's load balancer forwards to the app.
        $request = Request::create('/login');
        $request->headers->set('X-Forwarded-Proto', 'https');
        $request->headers->set('Host', 'takamol-production.up.railway.app');

        $response = $this->app->make(Kernel::class)->handle($request);

        $this->assertNotSame(500, $response->getStatusCode());

        $content = $response->getContent();

        $this->assertStringContainsString(
            'https://takamol-production.up.railway.app/login',
            $content,
        );
        $this->assertStringNotContainsString(
            'http://takamol-production.up.railway.app/login',
            $content,
        );
    }
}
