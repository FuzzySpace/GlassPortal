<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GlassBilling\GlassBillingClient;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingApprovalsController extends Controller
{
    public function __construct(private GlassBillingClient $billing) {}

    public function index(Request $request): View
    {
        $query  = array_filter($request->only(['status', 'page']));
        $result = $this->billing->invoiceApprovals($query);

        return view('admin.billing-approvals', [
            'approvals'    => $result->ok ? ($result->data['data'] ?? []) : [],
            'meta'         => $result->ok ? ($result->data['meta'] ?? []) : [],
            'billingOk'    => $result->ok,
            'billingError' => $result->ok ? null : ($result->error ?? null),
        ]);
    }

    public function show(string $id): View
    {
        $result = $this->billing->invoiceApproval($id);

        return view('admin.billing-approval-detail', [
            'approval'     => $result->ok ? $result->data : null,
            'billingOk'    => $result->ok,
            'billingError' => $result->ok ? null : ($result->error ?? null),
            'approvalId'   => $id,
        ]);
    }
}
