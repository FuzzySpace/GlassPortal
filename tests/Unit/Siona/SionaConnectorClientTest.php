<?php

namespace Tests\Unit\Siona;

use App\Services\Siona\SionaConnectorClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SionaConnectorClientTest extends TestCase
{
    private function client(): SionaConnectorClient
    {
        return new SionaConnectorClient();
    }

    // =========================================================================
    // Unconfigured states
    // =========================================================================

    public function test_health_returns_unconfigured_when_disabled(): void
    {
        config(['siona.enabled' => false, 'siona.api_url' => '']);

        $result = $this->client()->health();

        $this->assertFalse($result['ok']);
        $this->assertSame('unconfigured', $result['status']);
        $this->assertFalse($result['configured']);
        $this->assertNull($result['latency_ms']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_health_returns_unconfigured_when_enabled_but_no_url(): void
    {
        config(['siona.enabled' => true, 'siona.api_url' => '']);

        $result = $this->client()->health();

        $this->assertSame('unconfigured', $result['status']);
        $this->assertFalse($result['configured']);
    }

    public function test_is_configured_returns_false_when_disabled(): void
    {
        config(['siona.enabled' => false, 'siona.api_url' => 'http://siona.test']);

        $this->assertFalse($this->client()->isConfigured());
    }

    public function test_is_configured_returns_false_when_no_url(): void
    {
        config(['siona.enabled' => true, 'siona.api_url' => '']);

        $this->assertFalse($this->client()->isConfigured());
    }

    public function test_is_configured_returns_true_when_enabled_with_url(): void
    {
        config(['siona.enabled' => true, 'siona.api_url' => 'http://siona.test']);

        $this->assertTrue($this->client()->isConfigured());
    }

    // =========================================================================
    // Probe — success
    // =========================================================================

    public function test_health_returns_ok_on_successful_probe(): void
    {
        Http::fake(['siona.test/api/health' => Http::response(['status' => 'ok'], 200)]);

        config([
            'siona.enabled'   => true,
            'siona.api_url'   => 'http://siona.test',
            'siona.api_token' => 'test-token',
        ]);

        $result = $this->client()->health();

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['configured']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertSame('SIONA responded successfully.', $result['message']);
    }

    // =========================================================================
    // Probe — degraded / error
    // =========================================================================

    public function test_health_returns_degraded_on_non_2xx(): void
    {
        Http::fake(['siona.test/api/health' => Http::response([], 503)]);

        config([
            'siona.enabled'  => true,
            'siona.api_url'  => 'http://siona.test',
            'siona.api_token' => 'test-token',
        ]);

        $result = $this->client()->health();

        $this->assertFalse($result['ok']);
        $this->assertSame('degraded', $result['status']);
        $this->assertTrue($result['configured']);
        $this->assertIsInt($result['latency_ms']);
        $this->assertStringContainsString('503', $result['message']);
    }

    public function test_health_returns_error_on_connection_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        config([
            'siona.enabled'  => true,
            'siona.api_url'  => 'http://siona.test',
            'siona.api_token' => 'test-token',
        ]);

        $result = $this->client()->health();

        $this->assertFalse($result['ok']);
        $this->assertSame('error', $result['status']);
        $this->assertTrue($result['configured']);
    }

    public function test_health_never_throws(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('Unexpected failure');
        });

        config([
            'siona.enabled'  => true,
            'siona.api_url'  => 'http://siona.test',
        ]);

        $result = $this->client()->health();

        $this->assertSame('error', $result['status']);
        $this->assertArrayHasKey('message', $result);
    }

    // =========================================================================
    // Token leakage guards
    // =========================================================================

    public function test_health_never_includes_token_in_unconfigured_result(): void
    {
        $secret = 'siona-unit-test-token-must-not-leak';
        config(['siona.enabled' => false, 'siona.api_token' => $secret]);

        $result = $this->client()->health();

        $this->assertStringNotContainsString($secret, (string) json_encode($result));
    }

    public function test_health_never_includes_token_in_ok_result(): void
    {
        Http::fake(['siona.test/api/health' => Http::response([], 200)]);

        $secret = 'siona-probe-secret-must-not-leak';
        config([
            'siona.enabled'   => true,
            'siona.api_url'   => 'http://siona.test',
            'siona.api_token' => $secret,
        ]);

        $result = $this->client()->health();

        $this->assertStringNotContainsString($secret, (string) json_encode($result));
    }

    public function test_health_never_includes_token_in_error_result(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });

        $secret = 'siona-error-secret-must-not-leak';
        config([
            'siona.enabled'   => true,
            'siona.api_url'   => 'http://siona.test',
            'siona.api_token' => $secret,
        ]);

        $result = $this->client()->health();

        $this->assertStringNotContainsString($secret, (string) json_encode($result));
    }

    // =========================================================================
    // Result shape
    // =========================================================================

    public function test_health_result_always_has_required_keys(): void
    {
        config(['siona.enabled' => false]);

        $result = $this->client()->health();

        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('configured', $result);
        $this->assertArrayHasKey('latency_ms', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
    }

    // =========================================================================
    // launchMetadata
    // =========================================================================

    public function test_launch_metadata_returns_safe_fields(): void
    {
        $meta = $this->client()->launchMetadata();

        $this->assertArrayHasKey('configured', $meta);
        $this->assertArrayHasKey('launch_url', $meta);
        $this->assertArrayHasKey('display_name', $meta);
        $this->assertArrayHasKey('supported_auth_modes', $meta);
        $this->assertIsArray($meta['supported_auth_modes']);
        $this->assertContains('signed_launch', $meta['supported_auth_modes']);
        $this->assertContains('backchannel_launch', $meta['supported_auth_modes']);
    }

    public function test_launch_metadata_never_includes_token(): void
    {
        $secret = 'launch-meta-secret-must-not-appear';
        config(['siona.api_token' => $secret]);

        $meta = $this->client()->launchMetadata();

        $this->assertStringNotContainsString($secret, (string) json_encode($meta));
    }

    // =========================================================================
    // health_path config
    // =========================================================================

    public function test_probe_uses_configured_health_path(): void
    {
        Http::fake(['siona.test/custom/health' => Http::response([], 200)]);

        config([
            'siona.enabled'     => true,
            'siona.api_url'     => 'http://siona.test',
            'siona.health_path' => '/custom/health',
        ]);

        $result = $this->client()->health();

        $this->assertSame('ok', $result['status']);
    }
}
