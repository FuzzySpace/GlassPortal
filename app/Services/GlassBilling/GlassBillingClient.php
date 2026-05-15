<?php

namespace App\Services\GlassBilling;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GlassBillingClient
{
    private string $baseUrl;
    private string $token;
    private int    $timeout;
    private bool   $verifyTls;
    private bool   $configured;

    public function __construct()
    {
        $this->baseUrl    = rtrim((string) config('glassbilling.base_url', ''), '/');
        $this->token      = (string) config('glassbilling.token', '');
        $this->timeout    = (int) config('glassbilling.timeout', 8);
        $this->verifyTls  = (bool) config('glassbilling.verify_tls', true);
        $this->configured = $this->baseUrl !== '' && $this->token !== '';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function health(): array
    {
        if (! $this->configured) {
            return ['status' => 'unconfigured', 'detail' => 'GLASSBILLING_BASE_URL and GLASSBILLING_API_TOKEN are not set'];
        }

        $result = $this->get('/api/health');

        if ($result->ok) {
            $detail = $result->data['version'] ?? ($result->data['status'] ?? 'OK');
            return ['status' => 'online', 'detail' => (string) $detail, 'latency_ms' => $result->latency_ms];
        }

        return [
            'status'      => 'offline',
            'detail'      => $result->error ?? 'No response',
            'http_status' => $result->status,
            'latency_ms'  => $result->latency_ms,
        ];
    }

    public function dashboardTiles(): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/dashboard-tiles');
    }

    public function customerServices(array $query = []): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/customer-services', $query);
    }

    public function customerService(string $id): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/customer-services/' . rawurlencode($id));
    }

    public function customerServiceTimeline(string $id): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/customer-services/' . rawurlencode($id) . '/timeline');
    }

    public function customers(array $query = []): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/customers', $query);
    }

    public function customer(string $id): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/customers/' . rawurlencode($id));
    }

    public function provisioningRequests(array $query = []): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/provisioning-requests', $query);
    }

    public function provisioningRequest(string $id): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/provisioning-requests/' . rawurlencode($id));
    }

    public function invoiceApprovals(array $query = []): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/invoice-approvals', $query);
    }

    public function invoiceApproval(string $id): GlassBillingResult
    {
        if (! $this->configured) {
            return GlassBillingResult::unconfigured();
        }

        return $this->get('/api/v1/admin/invoice-approvals/' . rawurlencode($id));
    }

    // -------------------------------------------------------------------------

    private function get(string $path, array $query = []): GlassBillingResult
    {
        $start = microtime(true);

        try {
            $request = Http::timeout($this->timeout)
                ->withOptions(['verify' => $this->verifyTls])
                ->acceptJson();

            if ($this->token !== '') {
                $request = $request->withToken($this->token);
            }

            $url      = $this->baseUrl . $path;
            $response = $query ? $request->get($url, $query) : $request->get($url);
            $latency  = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return GlassBillingResult::success($response->json(), $response->status(), $latency);
            }

            $status = $response->status();
            $error  = $this->safeError($status);

            Log::warning('GlassBilling API error', [
                'path'       => $path,
                'status'     => $status,
                'latency_ms' => $latency,
            ]);

            return GlassBillingResult::failure($error, $status, $latency);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            Log::warning('GlassBilling connection failure', ['path' => $path, 'latency_ms' => $latency]);
            return GlassBillingResult::failure('GlassBilling is unreachable', null, $latency);

        } catch (\Throwable $e) {
            $latency = (int) round((microtime(true) - $start) * 1000);
            Log::warning('GlassBilling unexpected error', ['path' => $path, 'latency_ms' => $latency]);
            return GlassBillingResult::failure('GlassBilling request failed', null, $latency);
        }
    }

    private function safeError(int $status): string
    {
        return match (true) {
            $status === 401 => 'GlassBilling authentication failed (check API token)',
            $status === 403 => 'GlassBilling access denied',
            $status === 404 => 'GlassBilling resource not found',
            $status >= 500  => 'GlassBilling server error',
            default         => "GlassBilling returned HTTP {$status}",
        };
    }
}
