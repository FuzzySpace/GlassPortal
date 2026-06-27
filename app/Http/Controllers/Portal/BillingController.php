<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\BillingPlan;
use App\Services\Billing\StripeCheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Customer-facing checkout start flow (Phase 27).
 *
 * Lists active plans and starts a Stripe Checkout session. It never creates
 * billing/entitlement/provisioning records directly — those are driven by the
 * verified webhook after Stripe confirms payment. Stripe secrets are never
 * exposed.
 */
class BillingController extends Controller
{
    public function __construct(private StripeCheckoutService $checkout) {}

    public function plans(): View
    {
        return view('portal.billing-plans', [
            'plans'           => BillingPlan::active()->with('product')->orderBy('amount_cents')->get(),
            'checkoutEnabled' => (bool) config('billing.checkout.enabled', false),
        ]);
    }

    public function checkout(Request $request, BillingPlan $plan): RedirectResponse
    {
        $user   = $request->user();
        $result = $this->checkout->createSessionForPlan($plan, $user, $user->organization);

        if ($result->ok && $result->redirectUrl) {
            return redirect()->away($result->redirectUrl);
        }

        return redirect()->route('portal.billing.plans')->with('error', $result->message);
    }
}
