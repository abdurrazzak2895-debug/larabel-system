<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TakamolApiConsumerKey;
use App\Services\T2Hub\T2HubClient;
use App\Services\T2Hub\T2HubProvider;
use App\Services\T2Hub\T2HubSessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ExternalTakamolAvailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{TakamolApiConsumerKey, string} */
    private function createApiKey(array $attributes = []): array
    {
        $plaintext = TakamolApiConsumerKey::generatePlaintext();
        $key = TakamolApiConsumerKey::query()->create(array_merge([
            'name' => 'Partner website',
            'key_prefix' => TakamolApiConsumerKey::prefix($plaintext),
            'key_hash' => TakamolApiConsumerKey::hashPlaintext($plaintext),
            'rate_limit_per_minute' => 60,
        ], $attributes));

        return [$key, $plaintext];
    }

    public function test_key_authenticates_all_read_only_lookup_routes_through_the_t2hub_provider(): void
    {
        [$key, $plaintext] = $this->createApiKey();

        $this->mock(T2HubProvider::class, function ($mock): void {
            $mock->shouldReceive('occupations')->once()->with('welder')->andReturn([
                ['id' => 50, 'category_id' => 50, 'name' => 'Welder'],
            ]);
            $mock->shouldReceive('cities')->once()->with('50')->andReturn(['Dhaka']);
            $mock->shouldReceive('availableDates')->once()->with('50', 'Dhaka')->andReturn([
                'dates' => [['date' => '2026-10-25', 'city' => 'Dhaka']],
                'count' => 1,
            ]);
            $mock->shouldReceive('allTestCenters')->once()->andReturn([
                ['id' => '54', 'name' => 'Dhaka TTC'],
            ]);
            $mock->shouldReceive('testCenters')->once()->with('Dhaka')->andReturn([
                ['id' => '54', 'name' => 'Dhaka TTC'],
            ]);
            $mock->shouldReceive('sessions')->once()->with('50', 'Dhaka', '2026-10-25', '54')->andReturn([
                'sessions' => [
                    ['exam_session_id' => 'session-54', 'site_id' => '54', 'exam_date' => '2026-10-25'],
                    ['exam_session_id' => 'session-62', 'site_id' => '62', 'exam_date' => '2026-10-25'],
                ],
                'sites' => [['id' => '54', 'name' => 'Dhaka TTC']],
                'raw' => [],
            ]);
        });

        $this->getJson('/api/external/takamol-availability/v1/occupations?search=welder', ['X-Takamol-API-Key' => $plaintext])
            ->assertOk()->assertJsonPath('data.occupations.0.name', 'Welder');
        $this->getJson('/api/external/takamol-availability/v1/cities?category_id=50', ['X-Takamol-API-Key' => $plaintext])
            ->assertOk()->assertJsonPath('data.cities.0', 'Dhaka');
        $this->getJson('/api/external/takamol-availability/v1/dates?category_id=50&city=Dhaka', ['X-Takamol-API-Key' => $plaintext])
            ->assertOk()->assertJsonPath('data.dates.0.date', '2026-10-25');
        $this->getJson('/api/external/takamol-availability/v1/centers', ['X-Takamol-API-Key' => $plaintext])
            ->assertOk()->assertJsonPath('data.sites.0.name', 'Dhaka TTC');
        $this->getJson('/api/external/takamol-availability/v1/centers?city=Dhaka', ['X-Takamol-API-Key' => $plaintext])
            ->assertOk()->assertJsonPath('data.sites.0.id', '54');
        $this->getJson('/api/external/takamol-availability/v1/sessions?category_id=50&city=Dhaka&exam_date=2026-10-25&test_center_id=54', ['X-Takamol-API-Key' => $plaintext])
            ->assertOk()->assertJsonPath('data.sessions.0.exam_session_id', 'session-54')
            ->assertJsonCount(1, 'data.sessions')
            ->assertDontSee('saved-encrypted-bearer-token');

        $this->assertNotNull($key->fresh()->last_used_at);
        $this->assertFalse(Schema::hasColumn('takamol_api_consumer_keys', 'candidate_svp_session_id'));
    }

    public function test_missing_invalid_expired_and_revoked_keys_are_rejected(): void
    {
        $this->getJson('/api/external/takamol-availability/v1/occupations')->assertUnauthorized();
        $this->getJson('/api/external/takamol-availability/v1/occupations', ['X-Takamol-API-Key' => 'not-a-valid-key'])->assertUnauthorized();

        [, $expired] = $this->createApiKey(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/external/takamol-availability/v1/occupations', ['X-Takamol-API-Key' => $expired])->assertUnauthorized();

        [$revokedModel, $revoked] = $this->createApiKey();
        $revokedModel->forceFill(['revoked_at' => now()])->saveQuietly();
        $this->getJson('/api/external/takamol-availability/v1/occupations', ['X-Takamol-API-Key' => $revoked])->assertUnauthorized();
    }

    public function test_key_specific_rate_limit_is_enforced(): void
    {
        [, $plaintext] = $this->createApiKey(['rate_limit_per_minute' => 1]);
        $this->mock(T2HubProvider::class, function ($mock): void {
            $mock->shouldReceive('occupations')->once()->with(null)->andReturn([]);
        });

        $this->getJson('/api/external/takamol-availability/v1/occupations', ['X-Takamol-API-Key' => $plaintext])->assertOk();
        $this->getJson('/api/external/takamol-availability/v1/occupations', ['X-Takamol-API-Key' => $plaintext])->assertStatus(429);
    }

    public function test_admin_can_issue_and_revoke_a_key_without_session_mapping(): void
    {
        $permission = Permission::query()->create(['name' => 'manage agencies', 'slug' => 'manage-agencies']);
        $role = Role::query()->create(['name' => 'Takamol API Admin', 'slug' => 'takamol-api-admin']);
        $role->permissions()->attach($permission->id);
        $admin = Admin::factory()->create();
        $admin->assignRole($role->name);
        auth()->guard('admin')->login($admin);

        $client = Mockery::mock(T2HubClient::class);
        $client->shouldReceive('configured')->once()->andReturn(true);
        $store = Mockery::mock(T2HubSessionStore::class);
        $store->shouldReceive('summary')->once()->andReturn([
            'stored' => true,
            'fresh' => true,
            'has_key' => true,
        ]);
        $this->app->instance(T2HubClient::class, $client);
        $this->app->instance(T2HubSessionStore::class, $store);

        $this->get(route('admin.takamol-api-access.index'))
            ->assertOk()
            ->assertSee('shared-session API')
            ->assertSee('does not map to a candidate SVP session');

        $csrfToken = 'takamol-api-access-test-csrf-token';
        $creationResponse = $this->withSession(['_token' => $csrfToken])
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->post(route('admin.takamol-api-access.store'), [
                'name' => 'Partner website',
                'rate_limit_per_minute' => 25,
            ]);
        $createdPlaintext = null;
        $creationResponse->assertRedirect()->assertSessionHas('created_takamol_api_key', function ($value) use (&$createdPlaintext): bool {
            $createdPlaintext = $value;

            return is_string($value) && str_starts_with($value, 'takamol_live_');
        });

        $created = TakamolApiConsumerKey::query()->firstOrFail();
        $this->assertSame(TakamolApiConsumerKey::hashPlaintext($createdPlaintext), $created->key_hash);
        $this->assertNotSame($createdPlaintext, $created->key_hash);
        $this->assertArrayNotHasKey('key_hash', $created->toArray());
        $this->assertFalse(Schema::hasColumn('takamol_api_consumer_keys', 'candidate_svp_session_id'));

        $this->withSession(['_token' => $csrfToken])
            ->withHeader('X-CSRF-TOKEN', $csrfToken)
            ->post(route('admin.takamol-api-access.revoke', $created))
            ->assertRedirect();
        $this->assertNotNull($created->fresh()->revoked_at);
    }
}
