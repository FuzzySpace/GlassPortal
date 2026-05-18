<?php

namespace App\Http\Controllers\Api\Connectors;

use App\Http\Controllers\Controller;
use App\Services\Siona\SionaConnectorClient;
use Illuminate\Http\JsonResponse;

/**
 * Reports health of the SIONA external module connector.
 *
 * Always returns HTTP 200. Use the "status" field to determine health state.
 * Delegates to SionaConnectorClient — SIONA_API_TOKEN is never in the response.
 */
class SionaHealthController extends Controller
{
    public function index(SionaConnectorClient $client): JsonResponse
    {
        $result = $client->health();

        return response()->json([
            'connector'  => 'siona',
            'status'     => $result['status'],
            'configured' => $result['configured'],
            'latency_ms' => $result['latency_ms'],
            'message'    => $result['message'],
        ]);
    }
}
