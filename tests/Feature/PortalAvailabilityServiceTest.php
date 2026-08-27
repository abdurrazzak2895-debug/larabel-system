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

    public function test_transient_empty_center_response_is_retried_and_not_cached(): void
    {
        config(['portal.empty_retry_attempts' => 1, 'portal.empty_retry_delay_ms' => 0]);

        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Transient Empty Portal Session',
            'portal_account_id' => 'portal-account-transient',
            'session_cookie' => 'session=transient',
            'active' => true,
        ]);
        $calls = 0;
        $provider = new class($calls) implements PortalAvailabilityProviderInterface
        {
            public function __construct(private int &$calls)
            {
            }

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
                $this->calls++;

                return $this->calls <= 2
                    ? ['centers' => []]
                    : ['centers' => [[
                        'test_center_name' => 'Jashore TTC',
                        'test_center_id' => 171,
                        'test_time' => '10:00 AM',
                        'available_seats' => 4,
                        'exam_session_id' => 'recovered-session',
                    ]]];
            }
        };

        $service = new PortalAvailabilityService($provider);
        $empty = $service->centers($credential->id, 159, 'Khulna', '2026-08-31', 2061, 'LOABB');

        $this->assertSame([], $empty['data']['centers']);
        $this->assertSame(2, $calls);

        $result = $service->centers($credential->id, 159, 'Khulna', '2026-08-31', 2061, 'LOABB');

        $this->assertCount(1, $result['data']['centers']);
        $this->assertSame('recovered-session', $result['data']['centers'][0]['exam_session_id']);
        $this->assertSame(3, $calls);
    }

    public function test_retryable_center_provider_failure_is_retried_before_returning_live_data(): void
    {
        config(['portal.empty_retry_attempts' => 2, 'portal.empty_retry_delay_ms' => 0]);

        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Retryable provider failure session',
            'portal_account_id' => 'portal-account-retryable',
            'session_cookie' => 'session=retryable',
            'active' => true,
        ]);
        $calls = 0;
        $provider = new class($calls) implements PortalAvailabilityProviderInterface
        {
            public function __construct(private int &$calls)
            {
            }

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
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('Portal availability request failed for /api/centers with HTTP 503.');
                }

                return ['centers' => [[
                    'test_center_name' => 'Retry TTC',
                    'test_center_id' => 199,
                    'test_time' => '10:00 AM',
                    'available_seats' => 5,
                    'exam_session_id' => 'retry-session',
                ]]];
            }
        };

        $result = (new PortalAvailabilityService($provider))->centers(
            $credential->id,
            159,
            'Rajshahi',
            '2026-08-27',
            2061,
            'LOABB',
        );

        $this->assertSame(2, $calls);
        $this->assertSame('retry-session', $result['data']['centers'][0]['exam_session_id']);
    }

    public function test_auto_recovery_retries_transient_probe_and_marks_account_healthy(): void
    {
        config([
            'portal.recovery_retry_attempts' => 2,
            'portal.recovery_retry_delay_ms' => 0,
            'portal.recovery_retry_max_delay_ms' => 0,
        ]);

        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Recoverable session',
            'portal_account_id' => 'portal-account-recoverable',
            'session_cookie' => 'session=recoverable',
            'active' => true,
        ]);
        $calls = 0;
        $provider = new class($calls) implements PortalAvailabilityProviderInterface
        {
            public function __construct(private int &$calls)
            {
            }

            public function refreshAccount(string $sessionCookie, string $accountId): array
            {
                return ['session_cookie' => null, 'expires_at' => null, 'rotated' => false];
            }

            public function occupations(string $sessionCookie): array
            {
                $this->calls++;
                if ($this->calls < 3) {
                    throw new \RuntimeException('Portal availability request timed out.');
                }

                return [['name' => 'Load and Unload Worker', 'occupation_id' => 2061, 'category_id' => 159]];
            }

            public function searchDates(string $sessionCookie, string $accountId, int|string $categoryId, string $startFrom): array
            {
                return ['dates' => []];
            }

            public function centers(string $sessionCookie, string $accountId, int|string $categoryId, string $city, string $date, int|string $occupationId, string $languageCode): array
            {
                return ['centers' => []];
            }
        };

        $summary = (new PortalAvailabilityService($provider))->autoRecoverCredentials();
        $fresh = $credential->fresh();

        $this->assertSame(1, $summary['healthy']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame(3, $calls);
        $this->assertSame(0, $fresh->recovery_failures);
        $this->assertNull($fresh->circuit_open_until);
    }

    public function test_repeated_recovery_failures_open_a_temporary_circuit(): void
    {
        config([
            'portal.recovery_retry_attempts' => 0,
            'portal.recovery_failure_threshold' => 1,
            'portal.recovery_circuit_minutes' => 5,
        ]);

        $credential = PortalAvailabilityCredential::query()->create([
            'name' => 'Unavailable session',
            'portal_account_id' => 'portal-account-unavailable',
            'session_cookie' => 'session=unavailable',
            'active' => true,
        ]);

        $provider = new class implements PortalAvailabilityProviderInterface
        {
            public function refreshAccount(string $sessionCookie, string $accountId): array
            {
                throw new \RuntimeException('Portal session expired or not authorized.');
            }

            public function occupations(string $sessionCookie): array
            {
                throw new \RuntimeException('Portal session expired or not authorized.');
            }

            public function searchDates(string $sessionCookie, string $accountId, int|string $categoryId, string $startFrom): array
            {
                return ['dates' => []];
            }

            public function centers(string $sessionCookie, string $accountId, int|string $categoryId, string $city, string $date, int|string $occupationId, string $languageCode): array
            {
                return ['centers' => []];
            }
        };

        $summary = (new PortalAvailabilityService($provider))->autoRecoverCredentials();
        $fresh = $credential->fresh();

        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $fresh->recovery_failures);
        $this->assertTrue($fresh->circuitIsOpen());
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

    public function test_center_fallback_checks_up_to_four_accounts_for_september_02_and_03(): void
    {
        config([
            'portal.center_fallback_max_accounts' => 4,
            'portal.empty_retry_attempts' => 0,
        ]);

        $credentials = collect(range(1, 4))->map(function (int $number): PortalAvailabilityCredential {
            return PortalAvailabilityCredential::query()->create([
                'name' => 'Fallback account '.$number,
                'portal_account_id' => 'fallback-account-'.$number,
                'session_cookie' => 'session=fallback-'.$number,
                'active' => true,
            ]);
        });

        $provider = new class implements PortalAvailabilityProviderInterface
        {
            /** @var array<int, array{account:string,date:string}> */
            public array $calls = [];

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
                $this->calls[] = ['account' => $accountId, 'date' => $date];

                if ($date === '2026-09-02' && $accountId === 'fallback-account-1') {
                    return ['centers' => []];
                }

                if ($date === '2026-09-03' && $accountId === 'fallback-account-1') {
                    throw new \RuntimeException('Portal availability request failed for /api/centers with HTTP 503.');
                }

                if ($date === '2026-09-03' && $accountId === 'fallback-account-2') {
                    return ['centers' => []];
                }

                if ($date === '2026-09-02' && $accountId === 'fallback-account-2') {
                    return ['centers' => [[
                        'test_center_name' => 'Rajshahi TTC',
                        'test_center_id' => 172,
                        'test_time' => '10:00 AM',
                        'available_seats' => 7,
                        'exam_session_id' => 'sep02-account2-session',
                    ]]];
                }

                if ($date === '2026-09-02' && $accountId === 'fallback-account-3') {
                    return ['centers' => [[
                        'test_center_name' => 'Rajshahi TTC',
                        'test_center_id' => 172,
                        'test_time' => '10:00 AM',
                        'available_seats' => 11,
                        'exam_session_id' => 'sep02-account3-session',
                    ]]];
                }

                if ($date === '2026-09-02' && $accountId === 'fallback-account-4') {
                    return ['centers' => [[
                        'test_center_name' => 'Rajshahi Alternate TTC',
                        'test_center_id' => 173,
                        'test_time' => '11:00 AM',
                        'available_seats' => 3,
                        'exam_session_id' => 'sep02-account4-session',
                    ]]];
                }

                if ($date === '2026-09-03' && $accountId === 'fallback-account-3') {
                    return ['centers' => [[
                        'test_center_name' => 'Rajshahi TTC',
                        'test_center_id' => 172,
                        'test_time' => '10:00 AM',
                        'available_seats' => 11,
                        'exam_session_id' => 'sep03-account3-session',
                    ]]];
                }

                if ($date === '2026-09-03' && $accountId === 'fallback-account-4') {
                    return ['centers' => [[
                        'test_center_name' => 'Rajshahi Alternate TTC',
                        'test_center_id' => 173,
                        'test_time' => '11:00 AM',
                        'available_seats' => 4,
                        'exam_session_id' => 'sep03-account4-session',
                    ]]];
                }

                return ['centers' => []];
            }
        };

        $service = new PortalAvailabilityService($provider);
        $sep02 = $service->centersWithAccountFallback($credentials[0]->id, 159, 'Rajshahi', '2026-09-02', 2061, 'LOABB');
        $sep03 = $service->centersWithAccountFallback($credentials[0]->id, 159, 'Rajshahi', '2026-09-03', 2061, 'LOABB');

        $this->assertSame([$credentials[0]->id, $credentials[1]->id, $credentials[2]->id, $credentials[3]->id], $sep02['accounts_attempted']);
        $this->assertSame([$credentials[1]->id, $credentials[2]->id, $credentials[3]->id], $sep02['accounts_with_data']);
        $this->assertSame([$credentials[0]->id], $sep02['empty_accounts']);
        $this->assertTrue($sep02['fallback_used']);
        $this->assertCount(2, $sep02['data']['centers']);
        $this->assertSame('sep02-account2-session', $sep02['data']['centers'][0]['exam_session_id']);
        $this->assertSame(11, $sep02['data']['centers'][0]['available_seats']);
        $this->assertSame(['sep02-account2-session', 'sep02-account3-session'], $sep02['data']['centers'][0]['session_ids']);
        $this->assertSame(2, $sep02['data']['centers'][0]['session_count']);
        $this->assertSame(2, $sep02['data']['center_count']);

        $this->assertSame([$credentials[0]->id, $credentials[1]->id, $credentials[2]->id, $credentials[3]->id], $sep03['accounts_attempted']);
        $this->assertSame([$credentials[2]->id, $credentials[3]->id], $sep03['accounts_with_data']);
        $this->assertSame([$credentials[1]->id], $sep03['empty_accounts']);
        $this->assertCount(1, $sep03['failures']);
        $this->assertCount(2, $sep03['data']['centers']);
        $this->assertSame('sep03-account3-session', $sep03['data']['centers'][0]['exam_session_id']);
        $this->assertSame(['sep03-account3-session'], $sep03['data']['centers'][0]['session_ids']);
        $this->assertSame(2, $sep03['data']['center_count']);
        $this->assertSame([
            ['account' => 'fallback-account-1', 'date' => '2026-09-02'],
            ['account' => 'fallback-account-2', 'date' => '2026-09-02'],
            ['account' => 'fallback-account-3', 'date' => '2026-09-02'],
            ['account' => 'fallback-account-4', 'date' => '2026-09-02'],
            ['account' => 'fallback-account-1', 'date' => '2026-09-03'],
            ['account' => 'fallback-account-2', 'date' => '2026-09-03'],
            ['account' => 'fallback-account-3', 'date' => '2026-09-03'],
            ['account' => 'fallback-account-4', 'date' => '2026-09-03'],
        ], $provider->calls);
    }

    public function test_center_fallback_returns_all_empty_only_after_skipping_unusable_accounts(): void
    {
        config([
            'portal.center_fallback_max_accounts' => 4,
            'portal.empty_retry_attempts' => 0,
        ]);

        $readyOne = PortalAvailabilityCredential::query()->create([
            'name' => 'Ready empty one',
            'portal_account_id' => 'ready-empty-one',
            'session_cookie' => 'session=ready-empty-one',
            'active' => true,
        ]);
        PortalAvailabilityCredential::query()->create([
            'name' => 'Inactive empty account',
            'portal_account_id' => 'inactive-empty',
            'session_cookie' => 'session=inactive-empty',
            'active' => false,
        ]);
        PortalAvailabilityCredential::query()->create([
            'name' => 'Circuit open account',
            'portal_account_id' => 'circuit-open',
            'session_cookie' => 'session=circuit-open',
            'active' => true,
            'circuit_open_until' => now()->addMinutes(5),
        ]);
        $readyTwo = PortalAvailabilityCredential::query()->create([
            'name' => 'Ready empty two',
            'portal_account_id' => 'ready-empty-two',
            'session_cookie' => 'session=ready-empty-two',
            'active' => true,
        ]);

        $calls = [];
        $provider = new class($calls) implements PortalAvailabilityProviderInterface
        {
            public function __construct(private array &$calls)
            {
            }

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
                $this->calls[] = $accountId;
                return ['centers' => []];
            }
        };

        $result = (new PortalAvailabilityService($provider))->centersWithAccountFallback(
            $readyOne->id,
            159,
            'Rajshahi',
            '2026-09-03',
            2061,
            'LOABB',
        );

        $this->assertSame([$readyOne->id, $readyTwo->id], $result['accounts_attempted']);
        $this->assertSame([], $result['accounts_with_data']);
        $this->assertSame([$readyOne->id, $readyTwo->id], $result['empty_accounts']);
        $this->assertSame([], $result['failures']);
        $this->assertSame([], $result['data']['centers']);
        $this->assertSame(['ready-empty-one', 'ready-empty-two'], $calls);
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
                    [
                        'test_center_name' => 'Jashore TTC',
                        'test_center_id' => 171,
                        'test_time' => '09:30 AM',
                        'available_seats' => 5,
                        'exam_session_id' => 'portal-session-4',
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
            ['code' => 'LOABB', 'name' => 'Bengali', 'codes' => ['LOABB']],
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
        $this->assertSame(5, $slots[0]['available_seats']);
        $this->assertSame(['portal-session-2', 'portal-session-4'], $slots[0]['session_ids']);
        $this->assertSame(7, $slots[1]['available_seats']);
        $this->assertSame(['portal-session-2', 'portal-session-3'], array_column($slots, 'exam_session_id'));
        $this->assertSame([2, 1], array_column($slots, 'session_count'));
        $centers = $service->bookingCenters('159', 'Khulna', '2061', 'LOABB')['test_centers'];
        $this->assertCount(1, $centers);
        $this->assertSame('171', (string) $centers[0]['id']);
        $this->assertSame('Jashore TTC', $centers[0]['name']);
        $this->assertSame(5, $centers[0]['available_seats']);
    }

    public function test_booking_lookup_aggregates_multiple_portal_accounts(): void
    {
        PortalAvailabilityCredential::query()->create([
            'name' => 'Booking account one',
            'portal_account_id' => 'account-one',
            'session_cookie' => 'session=one',
            'active' => true,
        ]);
        PortalAvailabilityCredential::query()->create([
            'name' => 'Booking account two',
            'portal_account_id' => 'account-two',
            'session_cookie' => 'session=two',
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
                return $sessionCookie === 'session=one'
                    ? [[
                        'name' => 'Load Worker',
                        'occupation_id' => 2061,
                        'category_id' => 159,
                        'category_name' => 'Load Category',
                        'languages' => [['code' => 'LOABB', 'english_name' => 'Bengali']],
                    ]]
                    : [[
                        'name' => 'Machine Operator',
                        'occupation_id' => 2062,
                        'category_id' => 160,
                        'category_name' => 'Machine Category',
                        'languages' => [['code' => 'EN', 'english_name' => 'English']],
                    ]];
            }

            public function searchDates(string $sessionCookie, string $accountId, int|string $categoryId, string $startFrom): array
            {
                return $accountId === 'account-one'
                    ? ['dates' => [['city' => 'Khulna', 'date' => '2030-09-01']]]
                    : ['dates' => [['city' => 'Dhaka', 'date' => '2030-09-02']]];
            }

            public function centers(string $sessionCookie, string $accountId, int|string $categoryId, string $city, string $date, int|string $occupationId, string $languageCode): array
            {
                if ($accountId !== 'account-two') {
                    return ['centers' => []];
                }

                return ['centers' => [[
                    'test_center_name' => 'Dhaka TTC',
                    'test_center_id' => 172,
                    'test_time' => '10:00 AM',
                    'available_seats' => 8,
                    'exam_session_id' => 'account-two-session',
                ]]];
            }
        };

        $service = new PortalAvailabilityService($provider);

        $this->assertSame(['Load Worker', 'Machine Operator'], array_column($service->bookingOccupations(), 'name'));
        $this->assertSame([
            ['id' => '160', 'name' => 'Machine Category'],
        ], $service->bookingCategories('2062'));
        $this->assertSame([
            ['code' => 'EN', 'name' => 'English', 'codes' => ['EN']],
        ], $service->bookingLanguages('2062'));
        $this->assertSame([
            ['name' => 'Khulna'],
            ['name' => 'Dhaka'],
        ], $service->bookingCities('159'));
        $this->assertSame([
            ['city' => 'Dhaka', 'date' => '2030-09-02'],
        ], $service->bookingDates('160', 'Dhaka'));
        $centers = $service->bookingCentersForDate('160', 'Dhaka', '2030-09-02', '2062', 'EN');
        $this->assertSame('Dhaka TTC', $centers[0]['name']);
        $this->assertSame(8, $centers[0]['available_seats']);
    }

    public function test_booking_languages_consolidate_aliases_and_center_lookup_tries_all_codes(): void
    {
        PortalAvailabilityCredential::query()->create([
            'name' => 'Language alias account',
            'portal_account_id' => 'language-alias-account',
            'session_cookie' => 'session=language-alias',
            'active' => true,
        ]);

        $provider = new class implements PortalAvailabilityProviderInterface
        {
            /** @var array<int, string> */
            public array $centerLanguageCodes = [];

            public function refreshAccount(string $sessionCookie, string $accountId): array
            {
                return ['session_cookie' => null, 'expires_at' => null, 'rotated' => false];
            }

            public function occupations(string $sessionCookie): array
            {
                return [[
                    'name' => 'Load Worker',
                    'occupation_id' => 2061,
                    'category_id' => 159,
                    'category_name' => 'Load Category',
                    'languages' => [
                        ['code' => 'EN', 'name' => 'English'],
                        ['code' => 'EN-ALT', 'name' => 'English'],
                        ['code' => 'AR', 'name' => 'Arabic'],
                    ],
                ]];
            }

            public function searchDates(string $sessionCookie, string $accountId, int|string $categoryId, string $startFrom): array
            {
                return ['dates' => [['city' => 'Dhaka', 'date' => '2030-09-02']]];
            }

            public function centers(string $sessionCookie, string $accountId, int|string $categoryId, string $city, string $date, int|string $occupationId, string $languageCode): array
            {
                $this->centerLanguageCodes[] = $languageCode;
                return $languageCode === 'EN-ALT'
                    ? ['centers' => [[
                        'test_center_name' => 'Dhaka TTC',
                        'test_center_id' => 172,
                        'test_time' => '10:00 AM',
                        'available_seats' => 8,
                        'exam_session_id' => 'alias-session',
                    ]]]
                    : ['centers' => []];
            }
        };

        $service = new PortalAvailabilityService($provider);
        $languages = $service->bookingLanguages('2061');

        $this->assertSame([
            ['code' => 'EN', 'name' => 'English', 'codes' => ['EN', 'EN-ALT']],
            ['code' => 'AR', 'name' => 'Arabic', 'codes' => ['AR']],
        ], $languages);

        $centers = $service->bookingCentersForDate('159', 'Dhaka', '2030-09-02', '2061', 'EN', $languages[0]['codes']);
        $this->assertSame(['EN', 'EN', 'EN-ALT'], $provider->centerLanguageCodes);
        $this->assertSame('Dhaka TTC', $centers[0]['name']);
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

    public function test_admin_centers_endpoint_uses_selected_account_fallback_without_exposing_cookie(): void
    {
        $permission = Permission::query()->create(['name' => 'manage agencies', 'slug' => 'manage-agencies']);
        $role = Role::query()->create(['name' => 'Portal Center Fallback Admin', 'slug' => 'portal-center-fallback-admin']);
        $role->permissions()->attach($permission->id);
        $admin = Admin::factory()->create();
        $admin->assignRole($role->name);

        $preferred = PortalAvailabilityCredential::query()->create([
            'name' => 'Preferred empty account',
            'portal_account_id' => 'controller-preferred',
            'session_cookie' => 'session=controller-preferred',
            'active' => true,
        ]);
        $fallback = PortalAvailabilityCredential::query()->create([
            'name' => 'Fallback data account',
            'portal_account_id' => 'controller-fallback',
            'session_cookie' => 'session=controller-fallback',
            'active' => true,
        ]);

        config(['portal.empty_retry_attempts' => 0]);
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
                return $accountId === 'controller-fallback'
                    ? ['centers' => [[
                        'test_center_name' => 'Rajshahi TTC',
                        'test_center_id' => 172,
                        'test_time' => '10:00 AM',
                        'available_seats' => 6,
                        'exam_session_id' => 'controller-fallback-session',
                    ]]]
                    : ['centers' => []];
            }
        };
        $this->app->instance(PortalAvailabilityProviderInterface::class, $provider);
        Auth::guard('admin')->login($admin);

        $csrfToken = 'admin-centers-fallback-csrf-token';
        $response = $this->withSession(['_token' => $csrfToken])
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->postJson(route('admin.portal-availability.centers'), [
            'credential_id' => $preferred->id,
            'category_id' => 159,
            'city' => 'Rajshahi',
            'date' => '2026-09-02',
            'occupation_id' => 2061,
            'language_code' => 'LOABB',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.centers.0.test_center_name', 'Rajshahi TTC')
            ->assertJsonPath('data.data.centers.0.session_ids.0', 'controller-fallback-session')
            ->assertJsonPath('data.fallback_used', true)
            ->assertJsonPath('data.accounts_attempted.0', $preferred->id)
            ->assertJsonPath('data.accounts_attempted.1', $fallback->id)
            ->assertJsonPath('fallback_used', true)
            ->assertJsonMissing(['session_cookie' => 'session=controller-preferred'])
            ->assertJsonMissing(['session_cookie' => 'session=controller-fallback']);
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
            ->assertSee('No live centers returned after bounded retries.', false)
            ->assertSee('No stale availability is being shown.', false)
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
