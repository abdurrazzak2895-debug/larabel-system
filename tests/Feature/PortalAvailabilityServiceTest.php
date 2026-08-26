<?php

namespace Tests\Feature;

use App\Contracts\PortalAvailabilityProviderInterface;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\PortalAvailabilityApiKey;
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

    public function test_service_refreshes_and_encrypts_a_rotated_portal_cookie_without_exposing_it(): void
    {
        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Refreshable Portal Session',
            'portal_account_id' => 'ab9b8cf489a3',
            'session_cookie' => 'session=old-cookie',
            'active' => true,
        ]);
        $provider = $this->createMock(PortalAvailabilityProviderInterface::class);
        $provider->expects($this->once())
            ->method('refreshAccount')
            ->with('session=old-cookie', 'ab9b8cf489a3')
            ->willReturn([
                'session_cookie' => 'session=new-cookie',
                'expires_at' => '2030-09-01 12:00:00',
                'rotated' => true,
            ]);

        $result = (new PortalAvailabilityService($provider))->refreshCredential($credential);
        $fresh = $credential->fresh();

        $this->assertTrue($result['rotated']);
        $this->assertSame('ab9b8cf489a3', $result['credential']['portal_account_id']);
        $this->assertArrayNotHasKey('session_cookie', $result);
        $this->assertSame('session=new-cookie', $fresh->session_cookie);
        $this->assertNotNull($fresh->last_checked_at);
        $this->assertNull($fresh->last_error);
        $this->assertNotSame('session=new-cookie', DB::table('portal_availability_credentials')->whereKey($credential->id)->value('session_cookie'));
    }

    public function test_batch_refreshes_multiple_active_accounts_and_isolates_failures(): void
    {
        $first = PortalAvailabilityCredential::query()->create([
            'name' => 'First Portal Session',
            'portal_account_id' => 'portal-account-first',
            'session_cookie' => 'session=first',
            'active' => true,
        ]);
        $second = PortalAvailabilityCredential::query()->create([
            'name' => 'Second Portal Session',
            'portal_account_id' => 'portal-account-second',
            'session_cookie' => 'session=second',
            'active' => true,
        ]);
        PortalAvailabilityCredential::query()->create([
            'name' => 'Inactive Portal Session',
            'portal_account_id' => 'portal-account-inactive',
            'session_cookie' => 'session=inactive',
            'active' => false,
        ]);

        $provider = $this->createMock(PortalAvailabilityProviderInterface::class);
        $provider->expects($this->exactly(2))
            ->method('refreshAccount')
            ->willReturnCallback(function (string $cookie, string $accountId): array {
                if ($accountId === 'portal-account-second') {
                    throw new \RuntimeException('Second account is temporarily unavailable.');
                }

                return ['session_cookie' => null, 'expires_at' => null, 'rotated' => false];
            });

        $summary = (new PortalAvailabilityService($provider))->refreshCredentials();

        $this->assertSame(['refreshed' => 1, 'failed' => 1], [
            'refreshed' => $summary['refreshed'],
            'failed' => $summary['failed'],
        ]);
        $this->assertSame($second->id, $summary['failures'][0]['id']);
        $this->assertNotNull($first->fresh()->last_checked_at);
        $this->assertNotNull($second->fresh()->last_checked_at);
        $this->assertSame('Second account is temporarily unavailable.', $second->fresh()->last_error);
    }

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

    public function test_service_preserves_portal_session_identity_and_per_center_count(): void
    {
        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Authorized Portal Session',
            'portal_account_id' => 'portal-account-1',
            'session_cookie' => 'session=authorized',
            'active' => true,
        ]);

        $provider = new class implements PortalAvailabilityProviderInterface
        {
            public function refreshAccount(string $sessionCookie, string $accountId): array
            {
                return ['session_cookie' => null, 'expires_at' => null, 'rotated' => false];
            }

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
                        'exam_session_id' => 'portal-session-1',
                        'payable_id' => 'portal-payable-1',
                        'category_id' => 159,
                        'user_id' => 'portal-user-1',
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
            'exam_session_id' => 'portal-session-1',
            'category_id' => 159,
            'session_count' => 1,
        ], $center);
        $this->assertArrayNotHasKey('payable_id', $center);
        $this->assertArrayNotHasKey('user_id', $center);
    }

    public function test_service_unwraps_nested_center_payload_and_counts_all_session_rows(): void
    {
        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Nested center payload',
            'portal_account_id' => 'portal-account-nested',
            'session_cookie' => 'session=nested-authorized',
            'active' => true,
        ]);

        $provider = new class implements PortalAvailabilityProviderInterface
        {
            public function refreshAccount(string $sessionCookie, string $accountId): array
            {
                return ['session_cookie' => null, 'expires_at' => null, 'rotated' => false];
            }

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
                return ['data' => ['centers' => [
                    ['test_center_name' => 'Narail Technical Training Centre', 'test_center_id' => 181, 'test_time' => '02:30 PM', 'available_seats' => 16, 'exam_session_id' => 'nested-session-1'],
                    ['test_center_name' => 'Narail Technical Training Centre', 'test_center_id' => 181, 'test_time' => '09:30 AM', 'available_seats' => 16, 'exam_session_id' => 'nested-session-2'],
                    ['test_center_name' => 'Narail Technical Training Centre', 'test_center_id' => 181, 'test_time' => '11:30 AM', 'available_seats' => 16, 'exam_session_id' => 'nested-session-3'],
                    ['test_center_name' => 'Narail Technical Training Centre', 'test_center_id' => 181, 'test_time' => '01:30 PM', 'available_seats' => 16, 'exam_session_id' => 'nested-session-4'],
                ]]];
            }
        };

        $service = new PortalAvailabilityService($provider);
        $result = $service->centers($credential->id, 159, 'Khulna', '2026-08-31', 2061, 'LOABB');
        $rows = $result['data']['centers'];

        $this->assertCount(4, $rows);
        $this->assertSame(1, $result['data']['center_count']);
        $this->assertSame(['nested-session-1', 'nested-session-2', 'nested-session-3', 'nested-session-4'], array_column($rows, 'exam_session_id'));
        $this->assertSame([4, 4, 4, 4], array_column($rows, 'session_count'));
    }

    public function test_portal_booking_lookup_methods_return_verified_filter_data(): void
    {
        PortalAvailabilityCredential::query()->create([
            'name' => 'Booking lookup session',
            'portal_account_id' => 'portal-account-booking',
            'session_cookie' => 'session=booking-authorized',
            'active' => true,
        ]);

        $provider = new class implements PortalAvailabilityProviderInterface
        {
            public function refreshAccount(string $sessionCookie, string $accountId): array
            {
                return ['session_cookie' => null, 'expires_at' => null, 'rotated' => false];
            }

            public function occupations(string $sessionCookie): array
            {
                return [[
                    'name' => 'Load and Unload Worker',
                    'occupation_id' => 2061,
                    'category_id' => 159,
                    'category_name' => 'Load and unload workers',
                    'languages' => [['code' => 'LOABB', 'english_name' => 'Bengali']],
                ]];
            }

            public function searchDates(string $sessionCookie, string $accountId, int|string $categoryId, string $startFrom): array
            {
                return ['dates' => [['city' => 'Khulna', 'date' => '2030-09-01']]];
            }

            public function centers(string $sessionCookie, string $accountId, int|string $categoryId, string $city, string $date, int|string $occupationId, string $languageCode): array
            {
                return ['centers' => [
                    [
                        'test_center_name' => 'Jashore TTC',
                        'test_center_id' => 171,
                        'test_time' => '09:30 AM',
                        'available_seats' => 3,
                        'exam_session_id' => 'portal-session-2',
                    ],
                    [
                        'test_center_name' => 'Jashore TTC',
                        'test_center_id' => 171,
                        'test_time' => '11:00 AM',
                        'available_seats' => 7,
                        'exam_session_id' => 'portal-session-3',
                    ],
                ]];
            }
        };

        $service = new PortalAvailabilityService($provider);
        $this->assertSame('Load and Unload Worker', $service->bookingOccupations('load')[0]['name']);
        $this->assertSame([
            ['id' => '159', 'name' => 'Load and unload workers'],
        ], $service->bookingCategories('2061'));
        $this->assertSame([
            ['code' => 'LOABB', 'name' => 'Bengali'],
        ], $service->bookingLanguages('2061'));
        $this->assertSame([], $service->bookingLanguages('999999'));
        $this->assertSame([
            ['name' => 'Khulna'],
        ], $service->bookingCities('159'));
        $this->assertSame([
            ['city' => 'Khulna', 'date' => '2030-09-01'],
        ], $service->bookingDates('159', 'Khulna'));
        $slots = $service->bookingCentersForDate('159', 'Khulna', '2030-09-01', '2061', 'LOABB');
        $this->assertCount(2, $slots);
        $this->assertSame('09:30 AM', $slots[0]['test_time']);
        $this->assertSame(7, $slots[1]['available_seats']);
        $this->assertSame(['portal-session-2', 'portal-session-3'], array_column($slots, 'exam_session_id'));
        $this->assertSame([2, 2], array_column($slots, 'session_count'));
        $centers = $service->bookingCenters('159', 'Khulna', '2061', 'LOABB')['test_centers'];
        $this->assertCount(1, $centers);
        $this->assertSame('171', (string) $centers[0]['id']);
        $this->assertSame('Jashore TTC', $centers[0]['name']);
        $this->assertSame(3, $centers[0]['available_seats']);
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

    public function test_admin_can_refresh_all_active_portal_accounts_from_control(): void
    {
        $permission = Permission::query()->create(['name' => 'manage agencies', 'slug' => 'manage-agencies']);
        $role = Role::query()->create(['name' => 'Portal Refresh Admin', 'slug' => 'portal-refresh-admin']);
        $role->permissions()->attach($permission->id);
        $admin = Admin::factory()->create();
        $admin->assignRole($role->name);

        $first = PortalAvailabilityCredential::query()->create([
            'name' => 'First Portal Session',
            'portal_account_id' => 'portal-account-first',
            'session_cookie' => 'session=first',
            'active' => true,
        ]);
        $second = PortalAvailabilityCredential::query()->create([
            'name' => 'Second Portal Session',
            'portal_account_id' => 'portal-account-second',
            'session_cookie' => 'session=second',
            'active' => true,
        ]);
        Http::fake([
            'https://svp-international.xyz/api/accounts/*/refresh' => Http::response(['expires_at' => '2030-09-01 12:00:00'], 200),
        ]);
        Auth::guard('admin')->login($admin);
        $csrfToken = 'portal-refresh-all-csrf-token';

        $this->withSession(['_token' => $csrfToken])
            ->post(route('admin.portal-availability.refresh-all'), ['_token' => $csrfToken])
            ->assertRedirect()
            ->assertSessionHas('refresh_summary.refreshed', 2)
            ->assertSessionHas('refresh_summary.failed', 0);

        $this->assertNotNull($first->fresh()->last_checked_at);
        $this->assertNotNull($second->fresh()->last_checked_at);
        Http::assertSentCount(2);
    }

    public function test_admin_dashboard_renders_with_credential_and_api_key_data(): void
    {
        $permission = Permission::query()->create(['name' => 'manage agencies', 'slug' => 'manage-agencies']);
        $role = Role::query()->create(['name' => 'Portal Availability Dashboard Admin', 'slug' => 'portal-availability-dashboard-admin']);
        $role->permissions()->attach($permission->id);
        $admin = Admin::factory()->create();
        $admin->assignRole($role->name);

        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Authorized Portal Session',
            'portal_account_id' => 'portal-account-1',
            'session_cookie' => 'session=authorized',
            'active' => true,
        ]);
        $plaintext = PortalAvailabilityApiKey::generatePlaintext();
        PortalAvailabilityApiKey::query()->create([
            'portal_availability_credential_id' => $credential->id,
            'name' => 'Partner website',
            'key_prefix' => PortalAvailabilityApiKey::prefix($plaintext),
            'key_hash' => PortalAvailabilityApiKey::hashPlaintext($plaintext),
            'rate_limit_per_minute' => 60,
        ]);

        Auth::guard('admin')->login($admin);

        $this->get(route('admin.portal-availability.index'))
            ->assertOk()
            ->assertSee('Live occupations, dates and centers')
            ->assertSee('External website API access')
            ->assertSee('Partner website')
            ->assertSee('Active')
            ->assertSee('Refresh now')
            ->assertSee('Refresh all accounts now')
            ->assertSee('Server auto-refresh')
            ->assertDontSee('session=authorized');
    }

    public function test_guest_cannot_access_the_portal_dashboard_or_json_lookup_routes(): void
    {
        $this->get(route('admin.portal-availability.index'))
            ->assertRedirect(route('login'));

        $this->getJson(route('admin.portal-availability.occupations'))
            ->assertUnauthorized();
    }
}
