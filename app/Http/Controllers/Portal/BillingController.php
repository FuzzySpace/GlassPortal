<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\BillingCheckoutSession;
use App\Models\BillingInvoice;
use App\Models\BillingPlan;
use App\Models\BillingSubscription;
use App\Services\Billing\BillingSelfServiceService;
use App\Services\Billing\StripeCheckoutService;
use App\Services\Billing\InvoicePdfService;
use App\Services\Billing\StripePortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Customer-facing billing self-service (Phase 28) + checkout start (Phase 27).
 *
 * Strictly read-/request-only and scoped to the signed-in customer's billing
 * customers (their organization + themselves). A customer can never see another
 * organization's records, can never mutate billing/entitlement/provisioning
 * state, and is never shown raw provider payloads or secrets.
 */
class BillingController extends Controller
{
    public function __construct(
        private StripeCheckoutService $checkout,
        private BillingSelfServiceService $self,
        private InvoicePdfService $pdf,
        private StripePortalService $portal,
    ) {}

    // -------------------------------------------------------------------------
    // Dashboard

    public function dashboard(Request $request): View
    {
        return view('portal.billing.dashboard', [
            'data' => $this->self->dashboard($request->user()),
        ]);
    }

    // -------------------------------------------------------------------------
    // Subscriptions

    public function subscriptions(Request $request): View
    {
        return view('portal.billing.subscriptions', [
            'subscriptions' => $this->self->subscriptionsQuery($request->user())
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }

    public function subscriptionShow(Request $request, BillingSubscription $subscription): View
    {
        abort_unless($this->self->ownsSubscription($request->user(), $subscription), 404);

        $subscription->load(['plan.product', 'customer', 'serviceEntitlements', 'changeRequests']);

        // Related invoices via the same billing customer, read-only.
        $invoices = BillingInvoice::where('billing_customer_id', $subscription->billing_customer_id)
            ->orderByDesc('created_at')->limit(20)->get();

        return view('portal.billing.subscription-detail', [
            'subscription' => $subscription,
            'invoices'     => $invoices,
        ]);
    }

    // -------------------------------------------------------------------------
    // Invoices

    public function invoices(Request $request): View
    {
        return view('portal.billing.invoices', [
            'invoices' => $this->self->invoicesQuery($request->user())
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }

    public function invoiceShow(Request $request, BillingInvoice $invoice): View
    {
        abort_unless($this->self->ownsInvoice($request->user(), $invoice), 404);

        $invoice->load(['customer', 'payments']);

        return view('portal.billing.invoice-detail', ['invoice' => $invoice]);
    }

    // -------------------------------------------------------------------------
    // Payments

    public function payments(Request $request): View
    {
        return view('portal.billing.payments', [
            'payments'       => $this->self->paymentsQuery($request->user())
                ->orderByDesc('created_at')
                ->paginate(20),
            'paymentMethods' => $this->self->paymentMethods($request->user()),
        ]);
    }

    // -------------------------------------------------------------------------
    // Checkout session history

    public function checkoutSessions(Request $request): View
    {
        return view('portal.billing.checkout-sessions', [
            'sessions' => $this->self->checkoutSessionsQuery($request->user())
                ->orderByDesc('created_at')
                ->paginate(20),
        ]);
    }

    public function checkoutSessionShow(Request $request, BillingCheckoutSession $checkoutSession): View
    {
        abort_unless($this->self->ownsCheckoutSession($request->user(), $checkoutSession), 404);

        $checkoutSession->load(['plan', 'product', 'subscription']);

        return view('portal.billing.checkout-session-detail', ['session' => $checkoutSession]);
    }

    // -------------------------------------------------------------------------
    // Plans + checkout start (Phase 27)

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

    // -------------------------------------------------------------------------
    // Invoice PDF download (Phase 29D+)

    public function invoiceDownload(Request $request, BillingInvoice $invoice): Response
    {
        abort_unless($this->self->ownsInvoice($request->user(), $invoice), 404);

        $invoice->load(['customer', 'payments']);

        $content  = $this->pdf->generatePdf($invoice);
        $filename = $this->pdf->filename($invoice);

        $contentType = class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)
            ? 'application/pdf'
            : 'text/html';

        return response($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    // -------------------------------------------------------------------------
    // Stripe Customer Portal (Phase 29D+)

    public function stripePortal(Request $request): RedirectResponse
    {
        $result = $this->portal->createSession(
            $request->user(),
            url('/portal/billing'),
        );

        if ($result['ok'] && $result['url']) {
            return redirect()->away($result['url']);
        }

        return redirect()->route('portal.billing.dashboard')
            ->with('error', $result['error'] ?? 'Could not open billing management.');
    }
}
