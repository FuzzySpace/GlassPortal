<?php

namespace App\Services\Siona;

use Illuminate\Support\Facades\Http;

/**
 * GlassPortal-side connector client for the SIONA AI sales module.
 *
 * Reads config from config/siona.php. Never throws to callers — all errors
 * are caught and returned in the normalized result shape.
 *
 * Security invariants:
 * - SIONA_API_TOKEN is used only for outbound probe requests, never returned.
 * - Raw exception messages are sanitised before reaching the result.
 * - Credential-bearing URLs are stripped from error messages.
 */
class SionaConnectorClient
{
    /**
     * Probe SIONA health.
     *
     * Always returns a normalized array — never throws.
     *
     * @return array{ok: bool, status: string, configured: bool, latency_ms: int|null, message: string, data: array}
     */
    public function health(): array
    {
        $enabled = (bool) config('siona.enabled', false);
        $apiUrl  = (string) config('siona.api_url', '');

        if (! $enabled || $apiUrl === '') {
            return [
                'ok'         => false,
                'status'     => 'unconfigured',
                'configured' => false,
                'latency_ms' => null,
                'message'    => $enabled
                    ? 'SIONA_API_URL is not set. Configure SIONA_API_URL and SIONA_API_TOKEN to enable health probing.'
                    : 'SIONA connector is not enabled. Set SIONA_ENABLED=true and configure credentials to activate.',
                'data'       => [],
            ];
        }

        return $this->probe($apiUrl);
    }

    /**
     * Return safe display metadata for SIONA (no credentials, no tokens).
     *
     * @return array{configured: bool, launch_url: string, display_name: string, supported_auth_modes: string[]}
     */
    public function launchMetadata(): array
    {
        $launchModule = config('glasshouse.launch_modules.siona', []);

        return [
            'configured'           => $this->isConfigured(),
            'launch_url'           => (string) config('siona.launch_url', ''),
            'display_name'         => $launchModule['display_name'] ?? 'SIONA',
            'supported_auth_modes' => $launchModule['supported_auth_modes'] ?? ['standalone', 'signed_launch', 'backchannel_launch'],
        ];
    }

    /**
     * True when SIONA is enabled and has an API URL configured.
     */
    public function isConfigured(): bool
    {
        return (bool) config('siona.enabled', false)
            && (string) config('siona.api_url', '') !== '';
    }

    /**
     * True when the authenticated server-to-server back-channel to SIONA is
     * ready: SIONA enabled, API URL set, and an API token present. Required
     * for any outbound management call (e.g. tenant provisioning).
     *
     * Reports readiness only — never returns or logs the token itself.
     */
    public function isBackChannelReady(): bool
    {
        return $this->isConfigured()
            && (string) config('siona.api_token', '') !== '';
    }

    /**
     * True when the tenant-provisioning feature is configured and actionable:
     * back-channel ready AND the provisioning feature switch is enabled.
     */
    public function isProvisioningConfigured(): bool
    {
        return $this->isBackChannelReady()
            && (bool) config('siona.provisioning.enabled', false);
    }

    /**
     * Provision (create) a SIONA workspace/tenant over the back-channel.
     *
     * Always returns a normalized array — never throws. The SIONA_API_TOKEN is
     * used only to authenticate the outbound request and is NEVER returned or
     * echoed into the result/message. The SIONA response body is not forwarded;
     * only the extracted workspace_id is surfaced.
     *
     * @param array<string, mixed> $payload Safe org fields (no secrets).
     * @return array{ok: bool, status: string, workspace_id: string|null, message: string, http_status: int|null}
     */
    public function provisionTenant(array $payload): array
    {
        $enabled = (bool) config('siona.enabled', false);
        $apiUrl  = (string) config('siona.api_url', '');
        $token   = (string) config('siona.api_token', '');

        if (! $enabled || $apiUrl === '' || $token === '') {
            return [
                'ok'           => false,
                'status'       => 'unconfigured',
                'workspace_id' => null,
                'message'      => 'SIONA tenant provisioning is not configured. Set SIONA_ENABLED=true, SIONA_API_URL, and SIONA_API_TOKEN.',
                'http_status'  => null,
            ];
        }

        $timeout   = (int) config('siona.timeout', 5);
        $verifyTls = (bool) config('siona.verify_tls', true);
        $path      = '/' . ltrim((string) config('siona.provisioning.path', '/api/tenants'), '/');
        $url       = rtrim($apiUrl, '/') . $path;

        try {
            $response = Http::timeout($timeout)
                ->withOptions(['verify' => $verifyTls])
                ->withToken($token)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->successful()) {
                $workspaceId = $this->extractWorkspaceId($response->json());

                if ($workspaceId === null || $workspaceId === '') {
                    return [
                        'ok'           => false,
                        'status'       => 'error',
                        'workspace_id' => null,
                        'message'      => 'SIONA accepted the request but did not return a workspace_id.',
                        'http_status'  => $response->status(),
                    ];
                }

                return [
                    'ok'           => true,
                    'status'       => 'ok',
                    'workspace_id' => $workspaceId,
                    'message'      => 'SIONA workspace provisioned successfully.',
                    'http_status'  => $response->status(),
                ];
            }

            return [
                'ok'           => false,
                'status'       => 'degraded',
                'workspace_id' => null,
                'message'      => "SIONA tenant provisioning returned HTTP {$response->status()}.",
                'http_status'  => $response->status(),
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'ok'           => false,
                'status'       => 'error',
                'workspace_id' => null,
                'message'      => 'SIONA tenant provisioning connection failed: ' . $this->sanitise($e->getMessage()),
                'http_status'  => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'           => false,
                'status'       => 'error',
                'workspace_id' => null,
                'message'      => 'SIONA tenant provisioning failed: ' . $this->sanitise($e->getMessage()),
                'http_status'  => null,
            ];
        }
    }

    /**
     * Extract the workspace identifier from a SIONA tenant-creation response,
     * tolerating a few common envelope shapes. Returns null if none is present.
     */
    private function extractWorkspaceId(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        $candidates = [
            $body['workspace_id']           ?? null,
            $body['id']                     ?? null,
            $body['data']['workspace_id']   ?? null,
            $body['data']['id']             ?? null,
            $body['tenant']['workspace_id'] ?? null,
            $body['tenant']['id']           ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
            if (is_int($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }

    private function probe(string $apiUrl): array
    {
        $timeout   = (int) config('siona.timeout', 5);
        $verifyTls = (bool) config('siona.verify_tls', true);
        $token     = (string) config('siona.api_token', '');
        $path      = ltrim((string) config('siona.health_path', '/api/health'), '/');
        $probeUrl  = rtrim($apiUrl, '/') . '/' . $path;

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $request = Http::timeout($timeout)->withOptions(['verify' => $verifyTls]);

            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response  = $request->get($probeUrl);
            $latencyMs = (int) round(microtime(true) * 1000) - $startMs;

            if ($response->successful()) {
                return [
                    'ok'         => true,
                    'status'     => 'ok',
                    'configured' => true,
                    'latency_ms' => $latencyMs,
                    'message'    => 'SIONA responded successfully.',
                    'data'       => [],
                ];
            }

            return [
                'ok'         => false,
                'status'     => 'degraded',
                'configured' => true,
                'latency_ms' => $latencyMs,
                'message'    => "SIONA returned HTTP {$response->status()}.",
                'data'       => [],
            ];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $latencyMs = (int) round(microtime(true) * 1000) - $startMs;

            return [
                'ok'         => false,
                'status'     => 'error',
                'configured' => true,
                'latency_ms' => $latencyMs,
                'message'    => 'SIONA connection failed: ' . $this->sanitise($e->getMessage()),
                'data'       => [],
            ];
        } catch (\Throwable $e) {
            return [
                'ok'         => false,
                'status'     => 'error',
                'configured' => true,
                'latency_ms' => null,
                'message'    => 'SIONA health probe failed: ' . $this->sanitise($e->getMessage()),
                'data'       => [],
            ];
        }
    }

    private function sanitise(string $message): string
    {
        return preg_replace('/https?:\/\/[^@]*@/', 'https://<redacted>@', $message) ?? $message;
    }
}
