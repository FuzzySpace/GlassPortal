<?php

namespace Tests\Unit\PortalAuthSdk;

use GlassHouse\PortalAuth\DTO\BackChannelRedeemResult;
use Tests\TestCase;

class BackChannelRedeemResultTest extends TestCase
{
    public function test_from_response_builds_success_result(): void
    {
        $result = BackChannelRedeemResult::fromResponse([
            'ok'         => true,
            'module_key' => 'glasspanel',
            'user_id'    => '42',
            'org_id'     => '7',
            'email'      => 'user@example.com',
            'name'       => 'Test User',
            'role'       => 'customer',
            'expires_at' => 9999999999,
        ]);

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
        $this->assertSame('glasspanel', $result->moduleKey);
        $this->assertSame('42', $result->userId);
        $this->assertSame('7', $result->orgId);
        $this->assertSame('user@example.com', $result->email);
        $this->assertSame('Test User', $result->name);
        $this->assertSame('customer', $result->role);
    }

    public function test_from_error_response_builds_failure_result(): void
    {
        $result = BackChannelRedeemResult::fromErrorResponse([
            'ok'     => false,
            'error'  => 'Code redemption failed.',
            'reason' => 'wrong_module',
        ]);

        $this->assertFalse($result->ok);
        $this->assertSame('wrong_module', $result->reason);
        $this->assertNull($result->moduleKey);
        $this->assertNull($result->userId);
        $this->assertNull($result->email);
        $this->assertNull($result->name);
    }

    public function test_static_failure_factory(): void
    {
        $result = BackChannelRedeemResult::failure('code_not_found');

        $this->assertFalse($result->ok);
        $this->assertSame('code_not_found', $result->reason);
    }

    public function test_email_and_name_are_null_on_error_response(): void
    {
        $result = BackChannelRedeemResult::fromErrorResponse(['reason' => 'mtls_required']);

        $this->assertNull($result->email);
        $this->assertNull($result->name);
    }
}
