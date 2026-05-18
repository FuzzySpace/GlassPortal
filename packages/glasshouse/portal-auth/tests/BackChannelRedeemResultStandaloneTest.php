<?php

namespace GlassHouse\PortalAuth\Tests;

use GlassHouse\PortalAuth\DTO\BackChannelRedeemResult;
use PHPUnit\Framework\TestCase;

/**
 * Standalone unit tests for BackChannelRedeemResult DTO.
 * No Laravel required — runs with plain PHPUnit.
 */
class BackChannelRedeemResultStandaloneTest extends TestCase
{
    public function test_from_response_maps_all_fields(): void
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
        $this->assertSame('customer', $result->role);
    }

    public function test_from_error_response_maps_reason(): void
    {
        $result = BackChannelRedeemResult::fromErrorResponse([
            'ok'     => false,
            'reason' => 'wrong_module',
        ]);

        $this->assertFalse($result->ok);
        $this->assertSame('wrong_module', $result->reason);
        $this->assertNull($result->moduleKey);
        $this->assertNull($result->userId);
    }

    public function test_pii_is_null_in_failure_result(): void
    {
        $result = BackChannelRedeemResult::fromErrorResponse(['reason' => 'expired']);

        $this->assertNull($result->email);
        $this->assertNull($result->name);
    }

    public function test_static_failure_factory(): void
    {
        $result = BackChannelRedeemResult::failure('code_not_found');

        $this->assertFalse($result->ok);
        $this->assertSame('code_not_found', $result->reason);
    }

    public function test_unknown_reason_defaults_gracefully(): void
    {
        $result = BackChannelRedeemResult::fromErrorResponse([]);

        $this->assertFalse($result->ok);
        $this->assertSame('unknown', $result->reason);
    }
}
