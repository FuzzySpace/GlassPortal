<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\View\View;

class ServicesController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $services = $this->billing->customerServices();

        return view('admin.services', compact('services'));
    }
}
