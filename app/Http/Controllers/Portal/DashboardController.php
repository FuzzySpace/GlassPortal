<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use App\Models\BillingCustomer;
use App\Models\BillingSubscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $user       = Auth::user();

        // Onboarding: if no local billing customer or subscription exists,
        // show the getting-started flow instead of the legacy bridge dashboard.
        $hasLocalBilling = BillingCustomer::where('user_id', $user->id)
            ->orWhere('organization_id', $user->organization_id)
            ->exists();

        if (! $hasLocalBilling) {
            return view('portal.onboarding', ['user' => $user]);
        }

        $customerId = $user->organization?->glassbilling_customer_id;

        $services = null;
        if ($customerId) {
            $result   = $this->billing->customerServices(['customer_id' => $customerId, 'per_page' => 5]);
            $services = $result->ok ? ($result->data['data'] ?? []) : [];
        }

        return view('portal.dashboard', [
            'user'             => $user,
            'services'         => $services,
            'noLinkedCustomer' => $customerId === null,
        ]);
    }
}
