@extends('layouts.customer')

@section('title', 'Support')

@section('content')
<div class="page-header">
    <h2>Support</h2>
    <p>Submit and track support requests.</p>
</div>

{{-- Account context --}}
<div class="grid grid-2" style="margin-bottom:1.5rem">
    <div class="card">
        <div class="card-title" style="margin-bottom:.5rem">Your Account</div>
        <table style="width:100%">
            <tr><td class="text-dim text-sm" style="padding:.25rem 0;width:40%">Name</td><td class="text-sm" style="color:var(--text-h)">{{ $user->name }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.25rem 0">Email</td><td class="text-sm">{{ $user->email }}</td></tr>
            @if($org)
            <tr><td class="text-dim text-sm" style="padding:.25rem 0">Organization</td><td class="text-sm">{{ $org->name }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.25rem 0">Billing Email</td><td class="text-sm">{{ $org->billing_email ?? '—' }}</td></tr>
            @endif
        </table>
    </div>

    <div class="card">
        <div class="card-title" style="margin-bottom:.5rem">Billing Account</div>
        @if($billingLinked)
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem">
                <span class="badge badge-active">linked</span>
                <span class="text-sm text-dim">GlassBilling customer record found</span>
            </div>
            <div class="text-sm text-dim">Customer ID: <code>{{ $customerId }}</code></div>
        @else
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem">
                <span class="badge badge-unconfigured">not linked</span>
            </div>
            <p class="text-sm text-dim" style="margin:0">
                Your account is not yet linked to a GlassBilling record.
                Include your account name when contacting support.
            </p>
        @endif
    </div>
</div>

{{-- Support info --}}
<div class="alert alert-info" style="margin-bottom:1.5rem">
    Live support ticket integration is coming in Phase 6.
    For urgent issues, contact your account manager with your account details above.
</div>

<div class="card">
    <div class="card-title">Contact Options</div>
    <div style="margin-top:.75rem;line-height:1.8;font-size:.9rem">
        <p>Email: <span style="color:var(--text-h)">support@glasshouse.example</span></p>
        <p style="margin-top:.5rem;color:var(--text-dim)">
            When contacting support, please include your organization name
            @if($billingLinked) and customer ID <code>{{ $customerId }}</code>@endif.
        </p>
    </div>
</div>
@endsection
