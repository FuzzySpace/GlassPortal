@extends('layouts.staff')

@section('title', 'Entitlements')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

@if(session('success'))<div class="alert alert-info" style="margin-bottom:1rem;color:var(--success)">{{ session('success') }}</div>@endif

<div class="alert alert-info" style="margin-bottom:1rem">
    Service entitlements state <strong>what a customer is allowed to receive</strong> based on billing.
    Billing determines entitlement; a future provisioning request engine (Phase 26) fulfills it. No infrastructure is touched here.
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Name</th><th>Customer</th><th>Plan</th><th>Type</th><th>Status</th><th>Qty</th><th>Period end</th><th></th></tr></thead>
        <tbody>
            @forelse($entitlements as $e)
            <tr>
                <td style="color:var(--text-h)">{{ $e->name }}<div class="text-sm text-dim"><code>{{ $e->entitlement_key }}</code></div></td>
                <td class="text-sm text-dim">{{ $e->customer?->name ?? 'Customer #'.$e->billing_customer_id }}</td>
                <td class="text-sm text-dim">{{ $e->plan?->name ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $e->service_type ?? '—' }}</td>
                <td><span class="badge badge-{{ $e->isActive() ? 'active' : ($e->isTerminal() ? 'inactive' : 'pending') }}">{{ $e->status }}</span></td>
                <td class="text-sm text-dim">{{ $e->quantity }}</td>
                <td class="text-sm text-dim">{{ $e->current_period_end?->format('Y-m-d') ?? '—' }}</td>
                <td><a href="{{ route('admin.billing.entitlements.show', $e) }}" style="color:var(--accent);font-size:.8rem;text-decoration:none">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-dim" style="text-align:center;padding:2rem">No entitlements yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($entitlements->hasPages())<div style="margin-top:1rem">{{ $entitlements->links() }}</div>@endif
@endsection
