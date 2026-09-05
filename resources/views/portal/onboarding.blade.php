@extends('layouts.customer')
@section('title', 'Welcome')
@section('content')
<div style="max-width:640px;margin:2rem auto;text-align:center">
    <h1 style="font-size:1.8rem;font-weight:700;color:var(--text-h);margin-bottom:.5rem">Welcome to {{ config('app.name', 'GlassPortal') }}</h1>
    <p style="color:var(--text-dim);margin-bottom:2rem;line-height:1.6">
        Your account is ready. Here's how to get started with your first service.
    </p>

    <div style="display:grid;gap:1rem;text-align:left">
        <div class="card">
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
                <span style="font-size:1.3rem">1</span>
                <strong style="color:var(--text-h)">Browse available plans</strong>
            </div>
            <p class="text-dim text-sm" style="margin-bottom:.75rem">
                View our hosting and service plans to find the right fit for your needs.
            </p>
            <a href="{{ route('portal.billing.plans') }}" class="badge badge-active" style="text-decoration:none">View Plans →</a>
        </div>

        <div class="card">
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
                <span style="font-size:1.3rem">2</span>
                <strong style="color:var(--text-h)">Subscribe and pay securely</strong>
            </div>
            <p class="text-dim text-sm">
                Checkout is powered by Stripe. Your payment details are never stored on our servers.
            </p>
        </div>

        <div class="card">
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
                <span style="font-size:1.3rem">3</span>
                <strong style="color:var(--text-h)">Service provisioning</strong>
            </div>
            <p class="text-dim text-sm">
                After payment, your service request is reviewed and fulfilled by our team.
                You'll receive email updates at each step.
            </p>
        </div>

        <div class="card">
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
                <span style="font-size:1.3rem">4</span>
                <strong style="color:var(--text-h)">Manage everything here</strong>
            </div>
            <p class="text-dim text-sm">
                View subscriptions, invoices, entitlements, and provisioning status — all from your billing dashboard.
            </p>
            <a href="{{ route('portal.billing.dashboard') }}" class="badge badge-active" style="text-decoration:none;margin-top:.5rem">Billing Dashboard →</a>
        </div>
    </div>

    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--border)">
        <p class="text-dim text-sm">Need help? <a href="{{ route('portal.support') }}">Contact support</a></p>
    </div>
</div>
@endsection
