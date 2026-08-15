<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\SvpLoginController;
use App\Models\Agency;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
