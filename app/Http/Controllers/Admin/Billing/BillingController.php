<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingCustomer;
use App\Models\BillingEvent;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlan;
use App\Models\BillingProduct;
use App\Models\BillingSubscription;
use App\Services\Billing\StripeBillingClient;
use Illuminate\View\View;

/**
 * Read-only admin visibility into the GlassBilling foundation (Phase 24).
 *
 * Owner/admin only (enforced by stacked `role:owner,admin` route middleware).
 * Lists/detail only — no write operations in this phase. The Stripe config is
 * surfaced as presence booleans; secret values are never passed to views.
 */
class BillingController extends Controller
{
    public function __construct(private StripeBillingClient $stripe) {}

    public function overview(): View
    {
        return view('admin.billing.overview', [
            'stripeConfig' => $this->stripe->safeConfigSummary(),
            'counts'       => [
                'customers'            => BillingCustomer::count(),
                'products'             => BillingProduct::count(),
                'plans'                => BillingPlan::count(),
                'subscriptions'        => BillingSubscription::count(),
                'active_subscriptions' => BillingSubscription::active()->count(),
                'invoices'             => BillingInvoice::count(),
                'payments'             => BillingPayment::count(),
                'events'               => BillingEvent::count(),
            ],
        ]);
    }

    public function customers(): View
    {
        return view('admin.billing.customers', [
            'customers' => BillingCustomer::with('organization')
                ->withCount(['subscriptions', 'invoices'])
                ->orderByDesc('created_at')
                ->paginate(25),
        ]);
    }

    public function customerShow(BillingCustomer $customer): View
    {
        $customer->load(['organization', 'user', 'subscriptions.plan', 'invoices', 'payments', 'paymentMethods']);

        return view('admin.billing.customer-detail', ['customer' => $customer]);
    }

    public function products(): View
    {
        return view('admin.billing.products', [
            'products' => BillingProduct::with('catalogEntry')
                ->withCount('plans')
                ->orderBy('name')
                ->paginate(25),
        ]);
    }

    public function plans(): View
    {
        return view('admin.billing.plans', [
            'plans' => BillingPlan::with('product')
                ->orderByDesc('created_at')
                ->paginate(25),
        ]);
    }

    public function subscriptions(): View
    {
        return view('admin.billing.subscriptions', [
            'subscriptions' => BillingSubscription::with(['customer', 'plan'])
                ->orderByDesc('created_at')
                ->paginate(25),
        ]);
    }

    public function events(): View
    {
        return view('admin.billing.events', [
            'events' => BillingEvent::orderByDesc('created_at')->paginate(50),
        ]);
    }
}
