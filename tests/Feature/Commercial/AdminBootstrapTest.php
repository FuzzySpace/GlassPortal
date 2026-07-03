<?php

namespace Tests\Feature\Commercial;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 29D — admin bootstrap verification. The CLI bootstrap command must be
 * registered, create correctly-roled accounts with hashed passwords, enforce
 * validation, and the resulting accounts must clear the role walls.
 */
class AdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_command_is_registered(): void
    {
        $this->assertArrayHasKey('glassportal:create-admin', \Illuminate\Support\Facades\Artisan::all());
    }

    public function test_creates_owner_account_with_hashed_password(): void
    {
        $this->artisan('glassportal:create-admin', [
            '--name'  => 'Founder One',
            '--email' => 'founder@glasshouse.test',
            '--role'  => 'owner',
        ])
            ->expectsQuestion('Password (hidden)', 'a-very-long-password-1')
            ->expectsQuestion('Confirm password (hidden)', 'a-very-long-password-1')
            ->assertExitCode(0);

        $user = User::where('email', 'founder@glasshouse.test')->sole();
        $this->assertSame(UserRole::Owner, $user->role);
        $this->assertNotSame('a-very-long-password-1', $user->password);
        $this->assertTrue(password_verify('a-very-long-password-1', $user->password));
    }

    public function test_rejects_invalid_role_and_short_password_and_duplicate_email(): void
    {
        $this->artisan('glassportal:create-admin', [
            '--name' => 'X', '--email' => 'x@glasshouse.test', '--role' => 'customer',
        ])->assertExitCode(1);

        $this->artisan('glassportal:create-admin', [
            '--name' => 'Y', '--email' => 'y@glasshouse.test', '--role' => 'admin',
        ])
            ->expectsQuestion('Password (hidden)', 'short')
            ->assertExitCode(1);

        User::factory()->create(['email' => 'dup@glasshouse.test']);
        $this->artisan('glassportal:create-admin', [
            '--name' => 'Z', '--email' => 'dup@glasshouse.test', '--role' => 'admin',
        ])->assertExitCode(1);

        $this->assertSame(0, User::whereIn('email', ['x@glasshouse.test', 'y@glasshouse.test'])->count());
    }

    public function test_bootstrapped_owner_can_reach_admin_billing_but_not_customer_portal(): void
    {
        $this->artisan('glassportal:create-admin', [
            '--name'  => 'Founder Two',
            '--email' => 'founder2@glasshouse.test',
            '--role'  => 'owner',
        ])
            ->expectsQuestion('Password (hidden)', 'another-long-password-2')
            ->expectsQuestion('Confirm password (hidden)', 'another-long-password-2')
            ->assertExitCode(0);

        $owner = User::where('email', 'founder2@glasshouse.test')->sole();

        $this->actingAs($owner)->get('/admin/billing')->assertOk();
        $this->actingAs($owner)->get('/portal/billing')->assertForbidden();
    }
}
