<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\SvpLoginController;
use App\Models\Agency;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class SvpCandidateProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_nested_profile_id_refreshes_existing_candidate_without_synced_id(): void
    {
        $agency = Agency::create([
            'name' => 'Profile Sync Agency',
            'code' => 'PSYNC1',
            'status' => true,
        ]);
        $user = User::factory()->create(['agency_id' => $agency->id]);
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => $agency->id,
            'full_name' => 'Candidate Before Sync',
            'email' => $user->email,
            'svp_user_id' => null,
        ]);

        $controller = app(SvpLoginController::class);
        $extract = new ReflectionMethod($controller, 'extractProfileRecord');
        $profile = $extract->invoke($controller, [
            'data' => [
                'user' => [
                    'id' => 'SVP-USER-223',
                    'full_name' => 'Candidate After Sync',
                    'email' => 'candidate@example.test',
                ],
            ],
        ]);
        $sync = new ReflectionMethod($controller, 'syncCandidateFromProfile');
        $sync->invoke($controller, $user, $profile);

        $candidate->refresh();
        $this->assertSame('SVP-USER-223', $candidate->svp_user_id);
        $this->assertSame('Candidate After Sync', $candidate->full_name);
        $this->assertSame('candidate@example.test', $candidate->email);
        $this->assertSame(1, Candidate::where('user_id', $user->id)->count());
    }

    public function test_svp_sync_keeps_standalone_portal_user_without_creating_an_agency(): void
    {
        $agencyCount = Agency::count();
        $user = User::factory()->create(['agency_id' => null]);
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => null,
            'full_name' => 'Portal Candidate',
            'email' => $user->email,
            'svp_user_id' => null,
        ]);

        $controller = app(SvpLoginController::class);
        $sync = new ReflectionMethod($controller, 'syncCandidateFromProfile');
        $sync->invoke($controller, $user, [
            'id' => 'SVP-EXTERNAL-USER',
            'full_name' => 'SVP Candidate',
            'email' => 'svp-candidate@example.test',
        ]);

        $candidate->refresh();
        $user->refresh();

        $this->assertSame($agencyCount, Agency::count());
        $this->assertNull($user->agency_id);
        $this->assertSame('SVP-EXTERNAL-USER', $candidate->svp_user_id);
        $this->assertSame(1, Candidate::where('user_id', $user->id)->count());
    }

    public function test_otp_verification_keeps_svp_identity_on_existing_portal_user_without_agency_provisioning(): void
    {
        $agencyCount = Agency::count();
        $user = User::factory()->create(['agency_id' => null]);
        $candidate = Candidate::create([
            'user_id' => $user->id,
            'agency_id' => null,
            'full_name' => 'Portal Candidate',
            'email' => $user->email,
            'svp_user_id' => null,
        ]);

        Http::fake([
            '*/api/v1/sessions/otp' => Http::response([
                'access_payload' => [
                    'token' => 'svp-otp-token',
                    'user' => ['id' => 'SVP-OTP-USER'],
                ],
            ], 200),
            '*/api/v1/individual_labor_space/profile' => Http::response([
                'profile' => [
                    'id' => 'SVP-OTP-USER',
                    'full_name' => 'SVP Candidate',
                    'email' => 'svp-candidate@example.test',
                ],
            ], 200),
        ]);

        $csrfToken = 'svp-otp-csrf-token';
        $response = $this->actingAs($user, 'web')
            ->withSession([
                '_token' => $csrfToken,
                'svp_login' => [
                    'email' => 'svp-candidate@example.test',
                    'password' => 'secret',
                    'otp_method' => 'email',
                ],
            ])
            ->post(route('svp.otp.verify'), [
                'otp_code' => '123456',
                '_token' => $csrfToken,
            ]);

        $response->assertRedirect(route('user.dashboard'));
        $user->refresh();
        $candidate->refresh();

        $this->assertSame($agencyCount, Agency::count());
        $this->assertNull($user->agency_id);
        $this->assertSame('SVP-OTP-USER', $candidate->svp_user_id);
        $this->assertSame('svp-otp-token', session('svp_token'));
    }

    public function test_profile_envelope_is_normalized_to_the_actual_user_record(): void
    {
        $controller = app(SvpLoginController::class);
        $method = new ReflectionMethod($controller, 'extractProfileRecord');
        $profile = $method->invoke($controller, [
            'data' => [
                'profile' => [
                    'id' => 98765,
                    'first_name' => 'Rana',
                    'last_name' => 'Khan',
                ],
            ],
        ]);

        $this->assertSame(98765, $profile['id']);
        $this->assertSame('Rana', $profile['first_name']);
    }

    public function test_id_free_profile_payload_can_be_supplemented_from_otp_identity(): void
    {
        $controller = app(SvpLoginController::class);
        $extractProfile = new ReflectionMethod($controller, 'extractProfileRecord');
        $extractId = new ReflectionMethod($controller, 'extractSvpUserId');

        // This mirrors the live database payload: complete personal data, but
        // no account ID in the follow-up /profile response.
        $profile = $extractProfile->invoke($controller, [
            'data' => [
                'profile' => [
                    'full_name' => 'Rifat Ahamed',
                    'email' => 'rifatahmedsvp6643@yopmail.com',
                    'national_id' => '6932403097',
                ],
            ],
        ]);
        $loginId = $extractId->invoke($controller, [
            'data' => [
                'user' => ['id' => 'SVP-USER-6643'],
            ],
        ]);

        $this->assertSame('', $extractId->invoke($controller, $profile));
        $this->assertSame('SVP-USER-6643', $loginId);
    }

    public function test_account_id_is_extracted_from_access_payload_and_profile_aliases(): void
    {
        $controller = app(SvpLoginController::class);
        $extractId = new ReflectionMethod($controller, 'extractSvpUserId');

        $this->assertSame('SVP-ACCESS-17', $extractId->invoke($controller, [
            'access_payload' => ['user' => ['id' => 'SVP-ACCESS-17']],
        ]));
        $this->assertSame('SVP-ALIAS-45', $extractId->invoke($controller, [
            'profile' => ['svp_user_id' => 'SVP-ALIAS-45'],
        ]));
    }
}
