@extends('layouts.staff')

@section('title', 'Provisioning Request')
@section('page-title', 'Provisioning Request')

@section('content')

<div style="margin-bottom:1rem">
    <a href="{{ route('admin.provisioning') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Back to Provisioning</a>
</div>

@if(!$billingOk)
<div class="alert alert-warning" style="margin-bottom:1rem">
    {{ $billingError ?? 'Unable to load provisioning data.' }}
</div>
@elseif(!$request)
<div class="alert alert-warning" style="margin-bottom:1rem">
    Provisioning request <code>{{ $requestId }}</code> not found.
</div>
@else

<div class="grid grid-2" style="margin-bottom:1.5rem">
    <div class="card">
        <div class="section-title" style="margin-bottom:.75rem">Request Details</div>
        <table style="width:100%">
            <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">ID</td><td class="text-sm">{{ $request['id'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Product</td><td style="color:var(--text-h);font-weight:500">{{ $request['product_name'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Plan</td><td class="text-sm">{{ $request['plan_name'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Status</td><td><span class="badge badge-{{ $request['status'] ?? 'pending' }}">{{ $request['status'] ?? '—' }}</span></td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Priority</td><td class="text-sm">{{ $request['priority'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Requested</td><td class="text-sm">{{ $request['created_at'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Updated</td><td class="text-sm">{{ $request['updated_at'] ?? '—' }}</td></tr>
        </table>
    </div>

    <div class="card">
        <div class="section-title" style="margin-bottom:.75rem">Customer</div>
        <table style="width:100%">
            <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">Customer ID</td><td class="text-sm">{{ $request['customer_id'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Name</td><td style="color:var(--text-h)">{{ $request['customer_name'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Email</td><td class="text-sm">{{ $request['customer_email'] ?? '—' }}</td></tr>
        </table>

        <div class="section-title" style="margin-top:1.25rem;margin-bottom:.5rem">Provisioning Actions</div>
        <p class="text-sm text-dim">Approve / reject / provision actions are coming in Phase 5.</p>
    </div>
</div>

@if(!empty($request['notes']))
<div class="card" style="margin-bottom:1.5rem">
    <div class="section-title" style="margin-bottom:.5rem">Notes</div>
    <p class="text-sm" style="margin:0;white-space:pre-wrap">{{ $request['notes'] }}</p>
</div>
@endif

@endif
@endsection
