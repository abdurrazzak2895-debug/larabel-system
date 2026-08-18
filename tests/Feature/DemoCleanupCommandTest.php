<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCleanupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_demo_data_is_removed_without_touching_live_accounts(): void
    {
        $this->seed(\Database\Seeders\DemoSeeder::class);

        $liveAgency = Agency::create([
            'name' => 'Live Production Agency',
            'code' => 'LIVE01',
            'status' => true,
        ]);
        $liveUser = User::create([
            'agency_id' => $liveAgency->id,
            'name' => 'Live User',
            'username' => 'liveuser',
            'email' => 'live@example.com',
            'password' => 'secret-password',
            'status' => true,
        ]);
        $liveAdmin = Admin::create([
            'name' => 'Live Admin',
            'email' => 'live-admin@example.com',
            'password' => 'secret-password',
        ]);

        $this->artisan('app:purge-demo-data', ['--force' => true])
            ->assertExitCode(0);

        foreach (['ALNOOR', 'ALAMAL', 'SANAOV', 'YAQUET'] as $code) {
            $this->assertDatabaseMissing('agencies', ['code' => $code]);
        }

        $this->assertDatabaseMissing('users', ['username' => 'alnoor']);
        $this->assertDatabaseMissing('users', ['username' => 'alamal']);
        $this->assertDatabaseMissing('users', ['username' => 'sanaov']);
        $this->assertDatabaseMissing('users', ['username' => 'yaquet']);
        $this->assertDatabaseMissing('bookings', ['notes' => 'Seeded demo booking (pending)']);
        $this->assertDatabaseMissing('audit_logs', ['user_agent' => 'Seeder']);

        $this->assertDatabaseHas('agencies', ['id' => $liveAgency->id, 'code' => 'LIVE01']);
        $this->assertDatabaseHas('users', ['id' => $liveUser->id, 'email' => 'live@example.com']);
        $this->assertDatabaseHas('admins', ['id' => $liveAdmin->id, 'email' => 'live-admin@example.com']);
    }

    public function test_login_page_does_not_expose_demo_credentials(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Demo logins')
            ->assertDontSee('admin@takamol.example.com')
            ->assertDontSee('ChangeMe123!')
            ->assertDontSee('alnoor');
    }
}
