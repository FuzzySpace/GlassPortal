<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sso\SigningKeyResolver;
use Illuminate\Http\JsonResponse;

/**
 * Exposes safe JWKS-style key metadata for downstream modules.
 *
 * Response format (RFC 7517 — adapted for symmetric keys):
 *   GET /.well-known/glassportal/jwks.json
 *   { "keys": [ { "kid": "v2", "alg": "HS256", "use": "sig", "kty": "oct",
 *                 "status": "active", "iss": "glassportal" } ] }
 *
 * Security invariants:
 * - Raw secrets ("secret", "k") are NEVER included in the response.
 * - Disabled keys are excluded entirely.
 * - No authentication required — metadata is public by design (no secrets exposed).
 */
class JwksController extends Controller
{
    public function __construct(private readonly SigningKeyResolver $resolver)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(
            ['keys' => $this->resolver->publicKeyMetadata()],
            200,
            [
                'Cache-Control' => 'public, max-age=300',
                'Content-Type'  => 'application/json',
            ]
        );
    }
}
