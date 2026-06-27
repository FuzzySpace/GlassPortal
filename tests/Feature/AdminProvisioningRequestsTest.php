<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ProvisioningRequest;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 26 — admin provisioning request visibility + controlled lifecycle actions.
 */
class AdminProvisioningRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::Admin->value]);
    }

    private function staff(): User
    {
        return User::factory()->create(['role' => UserRole::Staff->value]);
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => UserRole::Customer->value]);
    }

    private function actionUrl(ProvisioningRequest $r, string $action): string
    {
        return route('admin.provisioning.requests.action', [$r, $action]);
    }

    // -------------------------------------------------------------------------
    // Access control
    // -------------------------------------------------------------------------

    public function test_admin_can_list_and_view(): void
    {
        $request = ProvisioningRequest::factory()->create();
        $admin   = $this->admin();

        $this->actingAs($admin)->get('/admin/provisioning/requests')
            ->assertStatus(200)->assertSeeText('Provisioning Requests');

        $this->actingAs($admin)->get(route('admin.provisioning.requests.show', $request))
            ->assertStatus(200)->assertSeeText('Event History');
    }

    public function test_customer_cannot_access_admin_provisioning(): void
    {
        $request = ProvisioningRequest::factory()->create();

        $this->actingAs($this->customer())->get('/admin/provisioning/requests')->assertForbidden();
        $this->actingAs($this->customer())->get(route('admin.provisioning.requests.show', $request))->assertForbidden();
    }

    public function test_staff_cannot_access_admin_provisioning(): void
    {
        $this->actingAs($this->staff())->get('/admin/provisioning/requests')->assertForbidden();
    }

    public function test_guest_cannot_access_admin_provisioning(): void
    {
        $this->get('/admin/provisioning/requests')->assertRedirect('/login');
    }

    // -------------------------------------------------------------------------
    // Lifecycle actions
    // -------------------------------------------------------------------------

    public function test_admin_can_walk_request_through_lifecycle(): void
    {
        $request = ProvisioningRequest::factory()->create(['status' => 'pending_approval']);
        $admin   = $this->admin();

        $this->actingAs($admin)->post($this->actionUrl($request, 'approve'), ['reason' => 'ok'])
            ->assertRedirect(route('admin.provisioning.requests.show', $request));
        $this->assertSame('approved', $request->fresh()->status);

        $this->actingAs($admin)->post($this->actionUrl($request, 'queue'));
        $this->assertSame('queued', $request->fresh()->status);

        $this->actingAs($admin)->post($this->actionUrl($request, 'start'));
        $this->assertSame('running', $request->fresh()->status);

        $this->actingAs($admin)->post($this->actionUrl($request, 'complete'));
        $this->assertSame('completed', $request->fresh()->status);

        $this->assertDatabaseHas('provisioning_request_events', [
            'provisioning_request_id' => $request->id,
            'event_type'              => 'approved',
        ]);
    }

    public function test_admin_can_reject_and_cancel(): void
    {
        $admin = $this->admin();

        $r1 = ProvisioningRequest::factory()->create(['status' => 'pending_approval']);
        $this->actingAs($admin)->post($this->actionUrl($r1, 'reject'), ['reason' => 'no']);
        $this->assertSame('rejected', $r1->fresh()->status);

        $r2 = ProvisioningRequest::factory()->create(['status' => 'pending_approval']);
        $this->actingAs($admin)->post($this->actionUrl($r2, 'cancel'));
        $this->assertSame('cancelled', $r2->fresh()->status);
    }

    public function test_invalid_action_redirects_with_error(): void
    {
        $request = ProvisioningRequest::factory()->status('completed')->create();

        $this->actingAs($this->admin())
            ->post($this->actionUrl($request, 'approve'))
            ->assertSessionHas('error');

        $this->assertSame('completed', $request->fresh()->status);
    }

    public function test_customer_cannot_run_action(): void
    {
        $request = ProvisioningRequest::factory()->create(['status' => 'pending_approval']);

        $this->actingAs($this->customer())
            ->post($this->actionUrl($request, 'approve'))
            ->assertForbidden();

        $this->assertSame('pending_approval', $request->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Secret hygiene
    // -------------------------------------------------------------------------

    public function test_admin_detail_redacts_sensitive_payload(): void
    {
        $secrets = [
            'api_token'      => 'LEAK_API_TOKEN_X',
            'secret'         => 'LEAK_SECRET_X',
            'password'       => 'LEAK_PASSWORD_X',
            'stripe_secret'  => 'LEAK_STRIPE_X',
            'signing_secret' => 'LEAK_SIGNING_X',
            'private_key'    => 'LEAK_PRIVATE_KEY_X',
        ];
        $request = ProvisioningRequest::factory()->create([
            'payload' => $secrets,
            'result'  => ['api_token' => 'LEAK_RESULT_TOKEN_X'],
            'status'  => 'completed',
        ]);

        $content = $this->actingAs($this->admin())
            ->get(route('admin.provisioning.requests.show', $request))
            ->getContent();

        foreach (array_merge(array_values($secrets), ['LEAK_RESULT_TOKEN_X']) as $value) {
            $this->assertStringNotContainsString($value, $content);
        }
    }
}
