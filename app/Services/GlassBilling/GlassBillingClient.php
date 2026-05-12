<?php

namespace App\Services\GlassBilling;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GlassBillingClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('glasshouse.glassbilling.base_url', ''), '/');
        $this->token   = config('glasshouse.glassbilling.token', '');
        $this->timeout = (int) config('glasshouse.glassbilling.timeout', 5);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * Ping the GlassBilling health endpoint.
     * Returns ['status' => 'online'|'offline'|'unconfigured', 'detail' => string].
     */
    public function health(): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'unconfigured', 'detail' => 'GLASSBILLING_API_URL or GLASSBILLING_API_TOKEN not set'];
        }

        try {
            $response = $this->client()->get('/api/health');

            if ($response->successful()) {
                return ['status' => 'online', 'detail' => $response->json('status', 'ok')];
            }

            return ['status' => 'offline', 'detail' => "HTTP {$response->status()}"];
        } catch (ConnectionException $e) {
            Log::warning('GlassBilling health check failed: connection error', ['error' => $e->getMessage()]);

            return ['status' => 'offline', 'detail' => 'Connection refused or timeout'];
        } catch (\Throwable $e) {
            Log::warning('GlassBilling health check failed', ['error' => $e->getMessage()]);

            return ['status' => 'offline', 'detail' => 'Unexpected error'];
        }
    }

    /**
     * Fetch high-level billing stats for the staff dashboard.
     * Returns safe stub payload when offline.
     */
    public function dashboardSummary(): array
    {
        return $this->safeGet('/api/portal/dashboard/summary', [
            'active_subscriptions' => null,
            'mrr_usd'              => null,
            'open_invoices'        => null,
            'pending_approvals'    => null,
            'status'               => 'offline',
        ]);
    }

    /**
     * Fetch the list of customer services.
     * Returns safe stub payload when offline.
     */
    public function customerServices(): array
    {
        return $this->safeGet('/api/portal/services', [
            'data'   => [],
            'status' => 'offline',
        ]);
    }

    /**
     * Fetch pending provisioning requests.
     * Returns safe stub payload when offline.
     */
    public function provisioningRequests(): array
    {
        return $this->safeGet('/api/portal/provisioning/requests', [
            'data'   => [],
            'status' => 'offline',
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function safeGet(string $path, array $offlinePayload): array
    {
        if (! $this->isConfigured()) {
            return array_merge($offlinePayload, ['status' => 'unconfigured']);
        }

        try {
            $response = $this->client()->get($path);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("GlassBilling GET {$path} returned HTTP {$response->status()}");

            return array_merge($offlinePayload, ['status' => 'offline', 'http_status' => $response->status()]);
        } catch (ConnectionException $e) {
            Log::warning("GlassBilling GET {$path} connection error", ['error' => $e->getMessage()]);

            return $offlinePayload;
        } catch (\Throwable $e) {
            Log::warning("GlassBilling GET {$path} unexpected error", ['error' => $e->getMessage()]);

            return $offlinePayload;
        }
    }

    private function client()
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->withToken($this->token)
            ->acceptJson();
    }
}
