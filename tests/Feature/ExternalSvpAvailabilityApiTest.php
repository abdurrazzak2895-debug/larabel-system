<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Candidate;
use App\Models\CandidateSvpSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SvpApiConsumerKey;
use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ExternalSvpAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    private function savedSession(?Carbon $expiresAt = null): CandidateSvpSession
    {
        $user = User::factory()->create();
        $candidate = Candidate::query()->create([
            'user_id' => $user->id,
            'full_name' => 'API Test Candidate',
            'svp_user_id' => 'svp-test-user-1',
        ]);

        return CandidateSvpSession::query()->create([
            'candidate_id' => $candidate->id,
            'user_id' => $user->id,
            'svp_user_id' => 'svp-test-user-1',
            'access_token' => 'saved-encrypted-bearer-token',
            'expires_at' => $expiresAt,
        ]);
    }

    private function apiKey(CandidateSvpSession $session, ?Carbon $expiresAt = null): string
    {
        $plaintext = SvpApiConsumerKey::generatePlaintext();
        SvpApiConsumerKey::query()->create([
            'candidate_svp_session_id' => $session->id,
            'name' => 'Partner website',
            'key_prefix' => SvpApiConsumerKey::prefix($plaintext),
            'key_hash' => SvpApiConsumerKey::hashPlaintext($plaintext),
            'expires_at' => $expiresAt,
            'rate_limit_per_minute' => 60,
        ]);

        return $plaintext;
    }

    public function test_key_uses_its_saved_session_for_occupations_without_returning_the_bearer_token(): void
    {
        $session = $this->savedSession();
        $key = $this->apiKey($session);
        $this->mock(BookingService::class, function ($mock): void {
            $mock->shouldReceive('occupations')
                ->once()
                ->with('saved-encrypted-bearer-token')
                ->andReturn(response()->json(['success' => true, 'data' => [['id' => 159]]], 200));
        });

        $this->withHeader('X-Portal-API-Key', $key)
            ->getJson('/api/external/svp-availability/v1/occupations')
            ->assertOk()
            ->assertJsonPath('data.0.id', 159)
            ->assertDontSee('saved-encrypted-bearer-token');

        $this->assertNotNull($session->fresh()->last_used_at);
    }

    public function test_dates_and_sessions_are_read_only_and_use_the_mapped_bearer_session(): void
    {
        $session = $this->savedSession();
        $key = $this->apiKey($session);
        $this->mock(BookingService::class, function ($mock): void {
            $mock->shouldReceive('cities')
                ->once()
                ->with('saved-encrypted-bearer-token', '159')
                ->andReturn(response()->json(['success' => true, 'data' => []], 200));
            $mock->shouldReceive('testCenters')
                ->once()
                ->with('saved-encrypted-bearer-token', 'Dhaka', '159')
                ->andReturn(response()->json(['success' => true, 'data' => []], 200));
            $mock->shouldReceive('availableDates')
                ->once()
                ->with('saved-encrypted-bearer-token', null, ['category_id' => '159', 'city' => 'Dhaka'])
                ->andReturn(response()->json(['success' => true, 'data' => []], 200));
            $mock->shouldReceive('sessionsForCenter')
                ->once()
                ->with('saved-encrypted-bearer-token', [
                    'category_id' => '159',
                    'city' => 'Dhaka',
                    'test_center_id' => '17',
                    'exam_date' => '2026-10-22',
                ])
                ->andReturn(response()->json(['success' => true, 'data' => []], 200));
        });

        $this->withHeader('X-Portal-API-Key', $key)
            ->getJson('/api/external/svp-availability/v1/cities?category_id=159')
            ->assertOk();
        $this->withHeader('X-Portal-API-Key', $key)
            ->getJson('/api/external/svp-availability/v1/centers?city=Dhaka&category_id=159')
            ->assertOk();
        $this->withHeader('X-Portal-API-Key', $key)
            ->getJson('/api/external/svp-availability/v1/dates?category_id=159&city=Dhaka')
            ->assertOk();
        $this->withHeader('X-Portal-API-Key', $key)
            ->getJson('/api/external/svp-availability/v1/sessions?category_id=159&city=Dhaka&test_center_id=17&exam_date=2026-10-22')
            ->assertOk();
    }

    public function test_missing_invalid_or_expired_session_keys_are_rejected(): void
    {
        $this->getJson('/api/external/svp-availability/v1/occupations')->assertUnauthorized();
        $this->withHeader('X-Portal-API-Key', 'svp_live_not-a-real-key')
            ->getJson('/api/external/svp-availability/v1/occupations')
            ->assertUnauthorized();

        $expiredSession = $this->savedSession(now()->subMinute());
        $key = $this->apiKey($expiredSession);
        $this->withHeader('X-Portal-API-Key', $key)
            ->getJson('/api/external/svp-availability/v1/occupations')
            ->assertUnauthorized();
    }

    public function test_admin_can_generate_and_revoke_a_key_for_a_saved_session(): void
    {
        $permission = Permission::query()->create(['name' => 'manage agencies', 'slug' => 'manage-agencies']);
        $role = Role::query()->create(['name' => 'SVP API Admin', 'slug' => 'svp-api-admin']);
        $role->permissions()->attach($permission->id);
        $admin = Admin::factory()->create();
        $admin->assignRole($role->name);
        $session = $this->savedSession();
        auth()->guard('admin')->login($admin);
        $csrfToken = 'svp-api-access-test-csrf-token';

        $this->get(route('admin.svp-api-access.index'))
            ->assertOk()
            ->assertSee('Generate a key for another website')
            ->assertDontSee('saved-encrypted-bearer-token');
        $creationResponse = $this->withSession(['_token' => $csrfToken])
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->post(route('admin.svp-api-access.store'), [
                'name' => 'Partner',
                'candidate_svp_session_id' => $session->id,
                'rate_limit_per_minute' => 60,
            ]);
        $createdPlaintext = null;
        $creationResponse->assertRedirect()->assertSessionHas('created_svp_api_key', function ($value) use (&$createdPlaintext): bool {
            $createdPlaintext = $value;

            return is_string($value) && str_starts_with($value, 'svp_live_');
        });

        $created = SvpApiConsumerKey::query()->firstOrFail();
        $this->assertSame($session->id, $created->candidate_svp_session_id);
        $this->assertNotEmpty($created->key_hash);
        $this->assertNotSame($createdPlaintext, $created->key_hash);

        $this->withSession(['_token' => $csrfToken])
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->post(route('admin.svp-api-access.revoke', $created))
            ->assertRedirect();
        $this->assertNotNull($created->fresh()->revoked_at);
    }
}
