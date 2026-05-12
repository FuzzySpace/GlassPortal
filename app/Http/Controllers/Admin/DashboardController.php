<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $billingHealth = $this->billing->health();
        $tiles         = $this->billing->dashboardTiles();
        $services      = $this->billing->customerServices(['per_page' => 1]);
        $provisioning  = $this->billing->provisioningRequests(['per_page' => 1]);
        $approvals     = $this->billing->invoiceApprovals(['per_page' => 1]);

        return view('admin.dashboard', [
            'billingHealth'   => $billingHealth,
            'tiles'           => $tiles->ok ? ($tiles->data ?? []) : [],
            'servicesTotal'   => $services->ok ? ($services->data['meta']['total'] ?? null) : null,
            'provisionTotal'  => $provisioning->ok ? ($provisioning->data['meta']['total'] ?? null) : null,
            'approvalsTotal'  => $approvals->ok ? ($approvals->data['meta']['total'] ?? null) : null,
            'billingOk'       => $tiles->ok,
            'billingError'    => $tiles->ok ? null : ($tiles->error ?? null),
            'billingLatency'  => $billingHealth['latency_ms'] ?? null,
        ]);
    }
}
