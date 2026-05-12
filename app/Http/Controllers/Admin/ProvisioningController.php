<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\View\View;

class ProvisioningController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $requests = $this->billing->provisioningRequests();

        return view('admin.provisioning', compact('requests'));
    }
}
