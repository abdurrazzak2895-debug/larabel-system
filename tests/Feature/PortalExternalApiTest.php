<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Permission;
use App\Models\PortalAvailabilityApiKey;
use App\Models\PortalAvailabilityCredential;
use App\Models\Role;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortalExternalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_external_occupations_requires_the_dedicated_api_key(): void
    {
        $response = $this->getJson(route('external.portal-availability.occupations'));

        $response->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_external_occupations_uses_the_mapped_session_and_returns_normalized_data(): void
    {
        Cache::flush();
        [$credential, $plaintext] = $this->createClient();
        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response([
                ['name' => 'Load and Unload Worker', 'occupation_id' => 2061, 'category_id' => 159, 'languages' => [['code' => 'LOABB', 'name' => 'Bengali']]],
            ], 200),
        ]);

        $response = $this->withHeader('X-Portal-API-Key', $plaintext)
            ->getJson(route('external.portal-availability.occupations'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.occupation_id', 2061)
            ->assertJsonPath('data.0.category_id', 159)
            ->assertJsonPath('data.0.languages.0.code', 'LOABB')
            ->assertJsonMissing(['session_cookie' => 'session=external-secret'])
            ->assertJsonMissing(['key_hash' => PortalAvailabilityApiKey::hashPlaintext($plaintext)]);

        Http::assertSent(function ($request) use ($credential): bool {
            return $request->method() === 'GET'
                && $request->url() === 'https://svp-international.xyz/api/occupations'
                && $request->header('Cookie')[0] === 'session=external-secret';
        });
        $this->assertNotNull($credential->fresh()->last_used_at);
    }

    public function test_external_dates_uses_the_key_mapped_account_and_exact_payload(): void
    {
        Cache::flush();
        [, $plaintext] = $this->createClient('mapped-account-42');
        Http::fake([
            'https://svp-international.xyz/api/search_dates' => Http::response([
                'dates' => [['city' => 'Khulna', 'date' => '2026-08-25']],
            ], 200),
        ]);

        $response = $this->withHeader('X-Portal-API-Key', $plaintext)
            ->postJson(route('external.portal-availability.search-dates'), [
                'category_id' => 159,
                'start_from' => '2026-08-22',
                'credential_id' => 999999,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.dates.0.city', 'Khulna')
            ->assertJsonPath('data.district_counts.Khulna', 1);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://svp-international.xyz/api/search_dates'
                && $request->data() === [
                    'account_id' => 'mapped-account-42',
                    'category_id' => '159',
                    'start_from' => '2026-08-22',
                ]
                && $request->header('Cookie')[0] === 'session=external-secret';
        });
    }

    public function test_external_centers_uses_exact_payload_and_exposes_only_slot_fields(): void
    {
        Cache::flush();
        [, $plaintext] = $this->createClient('mapped-account-42');
        Http::fake([
            'https://svp-international.xyz/api/centers' => Http::response([
                'centers' => [[
                    'test_center_name' => 'Jashore TTC',
                    'test_center_id' => 171,
                    'test_time' => '02:00 PM',
                    'available_seats' => '12',
                    'payable_id' => 'do-not-expose',
                    'user_id' => 'do-not-expose',
                ]],
            ], 200),
        ]);

        $response = $this->withHeader('X-Portal-API-Key', $plaintext)
            ->postJson(route('external.portal-availability.centers'), [
                'category_id' => 159,
                'city' => 'Khulna',
                'date' => '2026-08-25',
                'occupation_id' => 2061,
                'language_code' => 'LOABB',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.centers.0.test_center_id', 171)
            ->assertJsonPath('data.centers.0.available_seats', 12)
            ->assertJsonMissing(['payable_id' => 'do-not-expose'])
            ->assertJsonMissing(['user_id' => 'do-not-expose']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://svp-international.xyz/api/centers'
                && $request->data() === [
                    'account_id' => 'mapped-account-42',
                    'category_id' => '159',
                    'city' => 'Khulna',
                    'date' => '2026-08-25',
                    'occupation_id' => '2061',
                    'language_code' => 'LOABB',
                ];
        });
    }

    public function test_admin_can_issue_a_hashed_key_once_and_revoke_it(): void
    {
        $permission = Permission::query()->create(['name' => 'manage agencies', 'slug' => 'manage-agencies']);
        $role = Role::query()->create(['name' => 'Portal API Admin', 'slug' => 'portal-api-admin']);
        $role->permissions()->attach($permission->id);
        $admin = Admin::factory()->create();
        $admin->assignRole($role->name);
        Auth::guard('admin')->login($admin);

        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Authorized Portal Session',
            'portal_account_id' => 'portal-account-1',
            'session_cookie' => 'session=authorized',
            'active' => true,
        ]);

        $this->withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class, VerifyCsrfToken::class]);
        $response = $this->post(route('admin.portal-availability.api-keys.store'), [
            'name' => 'Partner Website',
            'portal_availability_credential_id' => $credential->id,
            'rate_limit_per_minute' => 30,
        ]);

        $response->assertRedirect()->assertSessionHas('created_api_key');
        $plaintext = $response->getSession()->get('created_api_key');
        $apiKey = PortalAvailabilityApiKey::query()->firstOrFail();
        $this->assertNotSame($plaintext, $apiKey->key_hash);
        $this->assertSame(PortalAvailabilityApiKey::hashPlaintext($plaintext), $apiKey->key_hash);
        $this->assertSame(30, $apiKey->rate_limit_per_minute);

        $this->post(route('admin.portal-availability.api-keys.revoke', $apiKey->id))
            ->assertRedirect();
        $this->assertNotNull($apiKey->fresh()->revoked_at);
    }

    public function test_revoked_api_key_cannot_call_the_portal(): void
    {
        [$credential, $plaintext] = $this->createClient();
        PortalAvailabilityApiKey::query()->where('portal_availability_credential_id', $credential->id)->update(['revoked_at' => now()]);
        Http::fake();

        $response = $this->withHeader('X-Portal-API-Key', $plaintext)
            ->getJson(route('external.portal-availability.occupations'));

        $response->assertUnauthorized();
        Http::assertNothingSent();
    }

    /** @return array{0: PortalAvailabilityCredential, 1: string} */
    private function createClient(string $accountId = 'portal-account-1'): array
    {
        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'External API Session',
            'portal_account_id' => $accountId,
            'session_cookie' => 'session=external-secret',
            'active' => true,
        ]);
        $plaintext = PortalAvailabilityApiKey::generatePlaintext();
        PortalAvailabilityApiKey::query()->create([
            'portal_availability_credential_id' => $credential->id,
            'name' => 'Partner Website',
            'key_prefix' => PortalAvailabilityApiKey::prefix($plaintext),
            'key_hash' => PortalAvailabilityApiKey::hashPlaintext($plaintext),
            'rate_limit_per_minute' => 60,
        ]);

        return [$credential, $plaintext];
    }
}
