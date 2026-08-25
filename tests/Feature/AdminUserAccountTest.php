<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AdminUserAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_create_user_and_is_redirected_to_admin_dashboard(): void
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('Super Admin');
        $agency = Agency::factory()->create();
        $role = Role::where('slug', 'agency-user')->firstOrFail();
        Auth::guard('admin')->login($admin);

        $csrfToken = 'admin-user-create-csrf-token';
        $response = $this->withSession(['_token' => $csrfToken])
            ->post(route('admin.users.store'), [
                '_token' => $csrfToken,
                'agency_id' => $agency->id,
                'name' => 'New Portal User',
                'email' => 'new.portal.user@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
                'status' => '1',
                'role_id' => $role->id,
                'portal_booking_fee' => '125.00',
            ]);

        $response->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('success', "User account 'New Portal User' created successfully.");

        $user = User::where('email', 'new.portal.user@example.com')->firstOrFail();
        $this->assertSame($agency->id, $user->agency_id);
        $this->assertTrue($user->hasRole('Agency User'));
        $this->assertSame('125.00', (string) $user->portal_booking_fee);

        $loginCsrf = 'new-user-login-csrf-token';
        $this->withSession(['_token' => $loginCsrf])
            ->post(route('login.attempt'), [
                '_token' => $loginCsrf,
                'login' => 'new.portal.user@example.com',
                'password' => 'Password123!',
            ])
            ->assertRedirect(route('user.dashboard'));
    }
}
