<?php

namespace App\Http\Controllers\Api\Connectors;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

/**
 * Reports health of the SIONA external module connector.
 *
 * Always returns HTTP 200. Use the "status" field in the body to determine
 * health state. This prevents false-alarm alerting on unconfigured state
 * (expected in dev and new deployments).
 *
 * Security invariants:
 * - SIONA_API_TOKEN is never included in any response body.
 * - Raw client exceptions are caught and sanitised before the browser sees them.
 * - Unconfigured SIONA is non-blocking: status=unconfigured, HTTP 200.
 */
class SionaHealthController extends Controller
{
    public function index(): JsonResponse
    {
        $enabled = (bool) config('siona.enabled', false);
        $apiUrl  = (string) config('siona.api_url', '');

        if (! $enabled || $apiUrl === '') {
            return response()->json([
                'connector'  => 'siona',
                'status'     => 'unconfigured',
                'configured' => false,
                'latency_ms' => null,
                'message'    => $enabled
                    ? 'SIONA_API_URL is not set. Configure SIONA_API_URL and SIONA_API_TOKEN to enable health probing.'
                    : 'SIONA connector is not enabled. Set SIONA_ENABLED=true and configure credentials to activate.',
            ]);
        }

        return $this->probeHealth($apiUrl);
    }

    private function probeHealth(string $apiUrl): JsonResponse
    {
        $timeout    = (int) config('siona.timeout', 5);
        $verifyTls  = (bool) config('siona.verify_tls', true);
        $healthPath = (string) config('siona.health_path', '/api/health');
        $token      = (string) config('siona.api_token', '');
        $probeUrl   = rtrim($apiUrl, '/') . $healthPath;

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $request = Http::timeout($timeout)
                ->withOptions(['verify' => $verifyTls]);

            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response  = $request->get($probeUrl);
            $latencyMs = (int) round(microtime(true) * 1000) - $startMs;

            if ($response->successful()) {
                return response()->json([
                    'connector'  => 'siona',
                    'status'     => 'ok',
                    'configured' => true,
                    'latency_ms' => $latencyMs,
                    'message'    => 'SIONA responded successfully.',
                ]);
            }

            return response()->json([
                'connector'  => 'siona',
                'status'     => 'degraded',
                'configured' => true,
                'latency_ms' => $latencyMs,
                'message'    => "SIONA returned HTTP {$response->status()}.",
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $latencyMs = (int) round(microtime(true) * 1000) - $startMs;

            return response()->json([
                'connector'  => 'siona',
                'status'     => 'error',
                'configured' => true,
                'latency_ms' => $latencyMs,
                'message'    => 'SIONA connection failed: ' . $this->sanitiseError($e->getMessage()),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'connector'  => 'siona',
                'status'     => 'error',
                'configured' => true,
                'latency_ms' => null,
                'message'    => 'SIONA health probe failed: ' . $this->sanitiseError($e->getMessage()),
            ]);
        }
    }

    /**
     * Strip credential-bearing URL patterns from exception messages
     * before they reach any response or log.
     */
    private function sanitiseError(string $message): string
    {
        return preg_replace('/https?:\/\/[^@]*@/', 'https://<redacted>@', $message) ?? $message;
    }
}
