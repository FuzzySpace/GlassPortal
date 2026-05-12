<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'glassbilling.base_url' => '',
            'glassbilling.token'    => '',
        ]);
    }

    public function test_healthcheck_passes_without_glassbilling_configured(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->assertExitCode(0);
    }

    public function test_healthcheck_shows_unconfigured_warning_for_glassbilling(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('not configured')
            ->assertExitCode(0);
    }

    public function test_healthcheck_strict_passes_when_glassbilling_online(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response(['status' => 'ok', 'version' => '1.0'], 200),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $this->artisan('glassportal:healthcheck --strict')
            ->assertExitCode(0);
    }

    public function test_healthcheck_strict_fails_when_glassbilling_offline(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response([], 503),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $this->artisan('glassportal:healthcheck --strict')
            ->assertExitCode(1);
    }

    public function test_healthcheck_non_strict_passes_even_when_glassbilling_offline(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response([], 503),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'test-token',
        ]);

        $this->artisan('glassportal:healthcheck')
            ->assertExitCode(0);
    }

    public function test_healthcheck_strict_fails_on_401(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response([], 401),
        ]);

        config([
            'glassbilling.base_url' => 'http://billing.test',
            'glassbilling.token'    => 'wrong-token',
        ]);

        $this->artisan('glassportal:healthcheck --strict')
            ->assertExitCode(1);
    }
}
