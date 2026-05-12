<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProvisioningController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(Request $request): View
    {
        $query  = array_filter($request->only(['status', 'page']));
        $result = $this->billing->provisioningRequests($query);

        return view('admin.provisioning', [
            'requests'     => $result->ok ? ($result->data['data'] ?? []) : [],
            'meta'         => $result->ok ? ($result->data['meta'] ?? []) : [],
            'billingOk'    => $result->ok,
            'billingError' => $result->ok ? null : ($result->error ?? null),
        ]);
    }

    public function show(string $id): View
    {
        $result = $this->billing->provisioningRequest($id);

        return view('admin.provisioning-detail', [
            'request'      => $result->ok ? $result->data : null,
            'billingOk'    => $result->ok,
            'billingError' => $result->ok ? null : ($result->error ?? null),
            'requestId'    => $id,
        ]);
    }
}
