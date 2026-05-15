<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServicesController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(Request $request): View
    {
        $query    = array_filter($request->only(['status', 'customer_id', 'page']));
        $result   = $this->billing->customerServices($query);

        return view('admin.services', [
            'services'    => $result->ok ? ($result->data['data'] ?? []) : [],
            'meta'        => $result->ok ? ($result->data['meta'] ?? []) : [],
            'billingOk'   => $result->ok,
            'billingError' => $result->ok ? null : ($result->error ?? null),
        ]);
    }

    public function show(string $id): View
    {
        $service  = $this->billing->customerService($id);
        $timeline = $service->ok ? $this->billing->customerServiceTimeline($id) : null;

        return view('admin.service-detail', [
            'service'       => $service->ok ? $service->data : null,
            'timeline'      => ($timeline && $timeline->ok) ? ($timeline->data ?? []) : [],
            'billingOk'     => $service->ok,
            'billingError'  => $service->ok ? null : ($service->error ?? null),
            'serviceId'     => $id,
        ]);
    }
}
