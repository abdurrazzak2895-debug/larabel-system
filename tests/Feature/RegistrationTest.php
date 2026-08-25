<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_login_page_links_to_registration_and_registration_creates_a_user_account(): void
    {
        $agency = Agency::factory()->create([
            'code' => 'SVP-7474',
            'status' => true,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(route('register'), false)
            ->assertSee('Create a new account');

        $csrfToken = 'registration-csrf-token';
        $response = $this->withSession(['_token' => $csrfToken])
            ->post(route('register.store'), [
                '_token' => $csrfToken,
                'name' => 'Registered Portal User',
                'username' => 'registered-user',
                'email' => 'registered.user@example.com',
                'agency_code' => 'SVP-7474',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

        $response->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $user = User::where('email', 'registered.user@example.com')->firstOrFail();
        $this->assertSame($agency->id, $user->agency_id);
        $this->assertTrue($user->hasRole('Agency User'));
        $this->assertDatabaseHas('user_wallets', ['user_id' => $user->id]);

        $loginCsrf = 'registration-login-csrf-token';
        $this->withSession(['_token' => $loginCsrf])
            ->post(route('login.attempt'), [
                '_token' => $loginCsrf,
                'login' => 'registered-user',
                'password' => 'Password123!',
            ])
            ->assertRedirect(route('user.dashboard'));
    }

    public function test_registration_rejects_unknown_or_inactive_agency_code(): void
    {
        $csrfToken = 'invalid-registration-csrf-token';
        $this->withSession(['_token' => $csrfToken])
            ->from(route('register'))
            ->post(route('register.store'), [
                '_token' => $csrfToken,
                'name' => 'Unassigned User',
                'username' => 'unassigned-user',
                'email' => 'unassigned@example.com',
                'agency_code' => 'OTHER-AGENCY',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertSessionHasErrors('agency_code');

        $this->assertDatabaseMissing('users', ['email' => 'unassigned@example.com']);
    }
}
