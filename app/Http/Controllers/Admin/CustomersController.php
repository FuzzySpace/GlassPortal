<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\GlassBilling\GlassBillingClient;
use App\Services\GlassBilling\GlassBillingResult;
use Illuminate\View\View;

class CustomersController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $organizations = Organization::withCount('users')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('admin.customers', [
            'organizations'     => $organizations,
            'billingConfigured' => $this->billing->isConfigured(),
        ]);
    }

    public function show(string $id): View
    {
        $org = Organization::with('users')->findOrFail($id);

        $gbId         = $org->glassbilling_customer_id;
        $gbCustomer   = null;
        $services     = GlassBillingResult::unconfigured();
        $provisioning = GlassBillingResult::unconfigured();
        $approvals    = GlassBillingResult::unconfigured();

        if ($gbId) {
            $gbCustomer   = $this->billing->customer($gbId);
            $services     = $this->billing->customerServices(['customer_id' => $gbId]);
            $provisioning = $this->billing->provisioningRequests(['customer_id' => $gbId]);
            $approvals    = $this->billing->invoiceApprovals(['customer_id' => $gbId]);
        }

        return view('admin.customer-detail', [
            'org'          => $org,
            'gbCustomer'   => ($gbCustomer && $gbCustomer->ok) ? $gbCustomer->data : null,
            'billingLinked' => $gbId !== null,
            'billingOk'    => $gbCustomer?->ok ?? false,
            'billingError' => ($gbCustomer && ! $gbCustomer->ok) ? $gbCustomer->error : null,
            'services'     => $services->ok ? ($services->data['data'] ?? []) : [],
            'provisioning' => $provisioning->ok ? ($provisioning->data['data'] ?? []) : [],
            'approvals'    => $approvals->ok ? ($approvals->data['data'] ?? []) : [],
            'servicesOk'   => $services->ok,
            'provisionOk'  => $provisioning->ok,
            'approvalsOk'  => $approvals->ok,
        ]);
    }
}
