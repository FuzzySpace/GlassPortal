<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $user     = Auth::user();
        $services = $this->billing->customerServices();

        return view('portal.dashboard', [
            'user'     => $user,
            'services' => $services,
        ]);
    }
}
