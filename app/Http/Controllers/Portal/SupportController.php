<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();
        $org  = $user->organization;

        return view('portal.support', [
            'user'          => $user,
            'org'           => $org,
            'billingLinked' => $org?->glassbilling_customer_id !== null,
            'customerId'    => $org?->glassbilling_customer_id,
        ]);
    }
}
