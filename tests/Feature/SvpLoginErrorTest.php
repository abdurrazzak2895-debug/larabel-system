<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SvpApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SvpLoginErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_base_url_with_existing_prefix_is_not_duplicated(): void
    {
        config()->set('svp.log_requests', false);
        Http::fake([
            'https://svp-international-api.pacc.sa/api/v1/sessions/login' => Http::response(['ok' => true], 200),
        ]);

        $result = (new SvpApiService(
            'https://svp-international-api.pacc.sa/api/v1',
            'svp-international',
            5,
        ))->login('candidate@example.com', 'not-recorded');

        $this->assertSame(200, $result['status']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://svp-international-api.pacc.sa/api/v1/sessions/login');
    }

    public function test_login_reports_endpoint_configuration_error_for_upstream_404(): void
    {
        $user = User::factory()->create(['agency_id' => null]);
        Auth::guard('web')->login($user);

        $this->mock(SvpApiService::class, function ($mock): void {
            $mock->shouldReceive('login')->once()->andReturn([
                'status' => 404,
                'body' => ['error' => 'Resource not found or no longer available'],
            ]);
        });

        $csrf = 'svp-login-test-csrf';
        $response = $this->withSession(['_token' => $csrf])
            ->from(route('svp.login.form'))
            ->post(route('svp.login.attempt'), [
                '_token' => $csrf,
                'email' => 'candidate@example.com',
                'password' => 'not-recorded',
            ]);

        $response->assertRedirect(route('svp.login.form'))
            ->assertSessionHasErrors([
                'email' => 'The SVP authentication endpoint was not found. Check the SVP base URL and tenant configuration.',
            ]);
    }

    public function test_login_preserves_safe_upstream_credential_message_without_raw_payload(): void
    {
        $user = User::factory()->create(['agency_id' => null]);
        Auth::guard('web')->login($user);

        $this->mock(SvpApiService::class, function ($mock): void {
            $mock->shouldReceive('login')->once()->andReturn([
                'status' => 401,
                'body' => ['message' => 'Invalid email or password', 'access_token' => 'must-not-render'],
            ]);
        });

        $csrf = 'svp-login-test-csrf';
        $response = $this->withSession(['_token' => $csrf])
            ->from(route('svp.login.form'))
            ->post(route('svp.login.attempt'), [
                '_token' => $csrf,
                'email' => 'candidate@example.com',
                'password' => 'not-recorded',
            ]);

        $response->assertRedirect(route('svp.login.form'))
            ->assertSessionHasErrors(['email' => 'Invalid email or password'])
            ->assertDontSee('must-not-render');
    }
}
