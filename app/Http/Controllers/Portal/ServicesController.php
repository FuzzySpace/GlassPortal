<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\View\View;

class ServicesController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $services = $this->billing->customerServices();

        return view('portal.services', compact('services'));
    }
}
