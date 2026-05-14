<?php

namespace Tests\Unit;

use App\Services\GlassBilling\GlassBillingClient;
use App\Services\GlassBilling\GlassBillingResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlassBillingClientTest extends TestCase
{
    private function makeClient(array $config = []): GlassBillingClient
    {
        config([
            'glassbilling.base_url'   => $config['base_url']   ?? '',
            'glassbilling.token'      => $config['token']       ?? '',
            'glassbilling.timeout'    => $config['timeout']     ?? 8,
            'glassbilling.verify_tls' => $config['verify_tls']  ?? true,
        ]);

        return new GlassBillingClient();
    }

    public function test_health_returns_unconfigured_when_no_base_url(): void
    {
        $client = $this->makeClient(['base_url' => '', 'token' => '']);

        $result = $client->health();

        $this->assertSame('unconfigured', $result['status']);
    }

    public function test_health_returns_unconfigured_when_no_token(): void
    {
        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => '']);

        $result = $client->health();

        $this->assertSame('unconfigured', $result['status']);
    }

    public function test_health_returns_online_on_success(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response(['status' => 'ok', 'version' => '1.2.3'], 200),
        ]);

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'test-token']);

        $result = $client->health();

        $this->assertSame('online', $result['status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/health'));
    }

    public function test_health_returns_offline_on_connection_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'test-token']);

        $result = $client->health();

        $this->assertSame('offline', $result['status']);
    }

    public function test_health_returns_offline_on_server_error(): void
    {
        Http::fake([
            'billing.test/api/health' => Http::response([], 500),
        ]);

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'test-token']);

        $result = $client->health();

        $this->assertSame('offline', $result['status']);
        $this->assertSame(500, $result['http_status']);
    }

    public function test_dashboard_tiles_returns_unconfigured_result(): void
    {
        $client = $this->makeClient();

        $result = $client->dashboardTiles();

        $this->assertInstanceOf(GlassBillingResult::class, $result);
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('not configured', $result->error ?? '');
    }

    public function test_customer_services_returns_data_on_success(): void
    {
        Http::fake([
            'billing.test/api/v1/admin/customer-services' => Http::response([
                'data' => [['id' => 'svc-1', 'product_name' => 'VPS Basic', 'status' => 'active']],
                'meta' => ['total' => 1],
            ], 200),
        ]);

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'test-token']);

        $result = $client->customerServices();

        $this->assertTrue($result->ok);
        $this->assertSame(200, $result->status);
        $this->assertCount(1, $result->data['data']);
        $this->assertSame('VPS Basic', $result->data['data'][0]['product_name']);
    }

    public function test_customer_services_returns_failure_result_on_401(): void
    {
        Http::fake([
            'billing.test/api/v1/admin/customer-services' => Http::response([], 401),
        ]);

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'bad-token']);

        $result = $client->customerServices();

        $this->assertFalse($result->ok);
        $this->assertSame(401, $result->status);
        $this->assertStringContainsString('authentication failed', $result->error ?? '');
    }

    public function test_customer_services_returns_failure_on_connection_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('Timed out'));

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'test-token']);

        $result = $client->customerServices();

        $this->assertFalse($result->ok);
        $this->assertNull($result->status);
        $this->assertStringContainsString('unreachable', $result->error ?? '');
    }

    public function test_bearer_token_is_sent_in_request(): void
    {
        Http::fake([
            'billing.test/*' => Http::response(['data' => [], 'meta' => []], 200),
        ]);

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'my-secret-token']);
        $client->customerServices();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-secret-token');
        });
    }

    public function test_provisioning_requests_returns_unconfigured_when_not_set(): void
    {
        $client = $this->makeClient();

        $result = $client->provisioningRequests();

        $this->assertInstanceOf(GlassBillingResult::class, $result);
        $this->assertFalse($result->ok);
    }

    public function test_invoice_approvals_returns_unconfigured_when_not_set(): void
    {
        $client = $this->makeClient();

        $result = $client->invoiceApprovals();

        $this->assertInstanceOf(GlassBillingResult::class, $result);
        $this->assertFalse($result->ok);
    }

    public function test_customers_returns_unconfigured_when_not_set(): void
    {
        $client = $this->makeClient();

        $result = $client->customers();

        $this->assertInstanceOf(GlassBillingResult::class, $result);
        $this->assertFalse($result->ok);
    }

    public function test_customers_returns_data_on_success(): void
    {
        Http::fake([
            'billing.test/api/v1/admin/customers' => Http::response([
                'data' => [
                    ['id' => 'gb_cust_1', 'name' => 'Acme Corp', 'email' => 'billing@acme.test', 'status' => 'active'],
                ],
                'meta' => ['total' => 1],
            ], 200),
        ]);

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'test-token']);

        $result = $client->customers();

        $this->assertTrue($result->ok);
        $this->assertCount(1, $result->data['data']);
        $this->assertSame('Acme Corp', $result->data['data'][0]['name']);
    }

    public function test_customer_returns_unconfigured_when_not_set(): void
    {
        $client = $this->makeClient();

        $result = $client->customer('gb_cust_123');

        $this->assertInstanceOf(GlassBillingResult::class, $result);
        $this->assertFalse($result->ok);
    }

    public function test_customer_returns_detail_on_success(): void
    {
        Http::fake([
            'billing.test/api/v1/admin/customers/gb_cust_abc' => Http::response([
                'id' => 'gb_cust_abc', 'name' => 'Test Co', 'email' => 'test@test.test', 'status' => 'active',
            ], 200),
        ]);

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'test-token']);

        $result = $client->customer('gb_cust_abc');

        $this->assertTrue($result->ok);
        $this->assertSame('Test Co', $result->data['name']);
    }

    public function test_customer_returns_failure_on_404(): void
    {
        Http::fake([
            'billing.test/api/v1/admin/customers/gb_cust_missing' => Http::response([], 404),
        ]);

        $client = $this->makeClient(['base_url' => 'http://billing.test', 'token' => 'test-token']);

        $result = $client->customer('gb_cust_missing');

        $this->assertFalse($result->ok);
        $this->assertSame(404, $result->status);
        $this->assertStringContainsString('not found', $result->error ?? '');
    }
}
