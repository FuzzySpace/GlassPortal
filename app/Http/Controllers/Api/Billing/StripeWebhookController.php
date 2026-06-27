<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Services\Billing\StripeBillingClient;
use App\Services\Billing\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Stripe webhook intake (Phase 27).
 *
 * Verifies the Stripe signature against STRIPE_WEBHOOK_SECRET before doing any
 * work, fails closed when webhooks are enabled but unconfigured, and never
 * exposes secrets. Returns 2xx for handled/duplicate/ignored events so Stripe
 * stops retrying once an event is durably recorded.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeBillingClient $stripe,
        private StripeWebhookService $webhooks,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        if (! (bool) config('billing.webhooks.enabled', false)) {
            return response()->json(['error' => 'webhooks_disabled'], 404);
        }

        // Fail closed: webhooks on but no signing secret = cannot verify anything.
        if (! $this->stripe->hasWebhookSecret()) {
            return response()->json(['error' => 'webhook_not_configured'], 500);
        }

        $payload   = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $tolerance = (int) config('billing.webhooks.tolerance', 300);

        if (! $this->stripe->verifyWebhookSignature($payload, $signature, $tolerance)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        $result = $this->webhooks->handle($event);

        return response()->json(['received' => true, 'status' => $result['status']], 200);
    }
}
