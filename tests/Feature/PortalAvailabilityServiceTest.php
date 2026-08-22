<?php

namespace Tests\Feature;

use App\Contracts\PortalAvailabilityProviderInterface;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\PortalAvailabilityCredential;
use App\Services\PortalAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\Role;
use Tests\TestCase;

class PortalAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_cookie_is_encrypted_and_hidden_from_model_arrays(): void
    {
        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Authorized Portal Session',
            'portal_account_id' => 'portal-account-1',
            'session_cookie' => 'session=do-not-expose',
            'active' => true,
        ]);

        $storedCookie = DB::table('portal_availability_credentials')->where('id', $credential->id)->value('session_cookie');

        $this->assertNotSame('session=do-not-expose', $storedCookie);
        $this->assertSame('session=do-not-expose', $credential->fresh()->session_cookie);
        $this->assertArrayNotHasKey('session_cookie', $credential->fresh()->toArray());
    }

    public function test_service_exposes_only_local_center_selection_fields(): void
    {
        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Authorized Portal Session',
            'portal_account_id' => 'portal-account-1',
            'session_cookie' => 'session=authorized',
            'active' => true,
        ]);

        $provider = new class implements PortalAvailabilityProviderInterface
        {
            public function occupations(string $sessionCookie): array
            {
                return [];
            }

            public function searchDates(string $sessionCookie, string $accountId, int|string $categoryId, string $startFrom): array
            {
                return ['dates' => []];
            }

            public function centers(string $sessionCookie, string $accountId, int|string $categoryId, string $city, string $date, int|string $occupationId, string $languageCode): array
            {
                return [
                    'centers' => [[
                        'test_center_name' => 'Jashore TTC',
                        'test_center_id' => 171,
                        'test_time' => '02:00 PM',
                        'available_seats' => '12',
                        'payable_id' => 'should-not-leave-service',
                        'user_id' => 'should-not-leave-service',
                    ]],
                ];
            }
        };

        $result = (new PortalAvailabilityService($provider))->centers(
            $credential->id,
            159,
            'Khulna',
            '2026-08-25',
            2061,
            'LOABB',
        );

        $center = $result['data']['centers'][0];
        $this->assertSame([
            'test_center_name' => 'Jashore TTC',
            'test_center_id' => 171,
            'test_time' => '02:00 PM',
            'available_seats' => 12,
        ], $center);
        $this->assertArrayNotHasKey('payable_id', $center);
        $this->assertArrayNotHasKey('user_id', $center);
    }

    public function test_admin_can_load_occupations_without_exposing_the_session_cookie(): void
    {
        $permission = Permission::query()->create(['name' => 'manage agencies', 'slug' => 'manage-agencies']);
        $role = Role::query()->create(['name' => 'Portal Availability Admin', 'slug' => 'portal-availability-admin']);
        $role->permissions()->attach($permission->id);
        $admin = Admin::factory()->create();
        $admin->assignRole($role->name);

        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Authorized Portal Session',
            'portal_account_id' => 'portal-account-1',
            'session_cookie' => 'session=authorized',
            'active' => true,
        ]);

        Http::fake([
            'https://svp-international.xyz/api/occupations' => Http::response([
                ['name' => 'Load and Unload Worker', 'occupation_id' => 2061, 'category_id' => 159, 'languages' => []],
            ], 200),
        ]);
        Auth::guard('admin')->login($admin);

        $response = $this->getJson(route('admin.portal-availability.occupations').'?credential_id='.$credential->id);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.0.occupation_id', 2061)
            ->assertJsonMissing(['session_cookie' => 'session=authorized'])
            ->assertDontSee('session=authorized');
        Http::assertSentCount(1);
    }

    public function test_guest_cannot_access_the_portal_dashboard_or_json_lookup_routes(): void
    {
        $this->get(route('admin.portal-availability.index'))
            ->assertRedirect(route('login'));

        $this->getJson(route('admin.portal-availability.occupations'))
            ->assertUnauthorized();
    }
}
