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
        $billingHealth  = $this->billing->health();
        $billingSummary = $this->billing->dashboardSummary();

        return view('admin.dashboard', [
            'billingHealth'  => $billingHealth,
            'billingSummary' => $billingSummary,
        ]);
    }
}
