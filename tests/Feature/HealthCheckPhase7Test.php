<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckPhase7Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['glassbilling.base_url' => '', 'glassbilling.token' => '']);
    }

    public function test_healthcheck_reports_module_launch_events_table(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('module_launch_events')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_module_launch_route(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('routes.module_launch')
            ->assertExitCode(0);
    }

    public function test_healthcheck_reports_launch_modules_config(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->expectsOutputToContain('config.launch_modules')
            ->assertExitCode(0);
    }

    public function test_healthcheck_passes_with_all_phase7_checks(): void
    {
        $this->artisan('glassportal:healthcheck')
            ->assertExitCode(0);
    }
}
