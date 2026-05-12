<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ServicesController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(): View
    {
        $user       = Auth::user();
        $customerId = $user->organization?->glassbilling_customer_id;

        if (! $customerId) {
            return view('portal.services', [
                'services'         => [],
                'billingOk'        => false,
                'noLinkedCustomer' => true,
                'billingError'     => null,
            ]);
        }

        $result = $this->billing->customerServices(['customer_id' => $customerId]);

        return view('portal.services', [
            'services'         => $result->ok ? ($result->data['data'] ?? []) : [],
            'billingOk'        => $result->ok,
            'noLinkedCustomer' => false,
            'billingError'     => $result->ok ? null : ($result->error ?? null),
        ]);
    }
}
