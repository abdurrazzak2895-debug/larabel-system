<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_public_homepage_renders_the_login_page(): void
    {
        $response = $this->get('/');

        $response->assertOk()->assertViewIs('auth.login');
    }
}
