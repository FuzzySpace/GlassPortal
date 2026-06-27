@extends('layouts.staff')

@section('title', 'Billing')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div class="alert alert-info" style="margin-bottom:1.5rem">
    GlassBilling is the billing/account/subscription/payment <strong>source of truth</strong>
    (Stripe-first). This is the Phase 24 foundation: read-only visibility into local billing
    records. No secrets are shown here. See <code>docs/phase24/stripe-billing-foundation.md</code>.
</div>

{{-- Stripe configuration (presence only — never values) --}}
<div class="card" style="margin-bottom:1.5rem">
    <div class="section-title" style="margin-bottom:.75rem">Stripe Configuration</div>
    <table style="width:100%">
        <tr>
            <td class="text-dim text-sm" style="padding:.3rem 0;width:45%">Billing enabled</td>
            <td><span class="badge badge-{{ $stripeConfig['enabled'] ? 'active' : 'inactive' }}">{{ $stripeConfig['enabled'] ? 'yes' : 'no' }}</span></td>
        </tr>
        <tr>
            <td class="text-dim text-sm" style="padding:.3rem 0">Mode</td>
            <td class="text-sm"><code>{{ $stripeConfig['mode'] }}</code></td>
        </tr>
        <tr>
            <td class="text-dim text-sm" style="padding:.3rem 0">Stripe configured</td>
            <td><span class="badge badge-{{ $stripeConfig['stripe_configured'] ? 'active' : 'pending' }}">{{ $stripeConfig['stripe_configured'] ? 'yes' : 'no' }}</span></td>
        </tr>
        <tr>
            <td class="text-dim text-sm" style="padding:.3rem 0">Secret key present</td>
            <td class="text-sm">{{ $stripeConfig['has_secret_key'] ? '✓' : '—' }}</td>
        </tr>
        <tr>
            <td class="text-dim text-sm" style="padding:.3rem 0">Webhook secret present</td>
            <td class="text-sm">{{ $stripeConfig['has_webhook_secret'] ? '✓' : '—' }}</td>
        </tr>
        <tr>
            <td class="text-dim text-sm" style="padding:.3rem 0">Publishable key present</td>
            <td class="text-sm">{{ $stripeConfig['has_publishable_key'] ? '✓' : '—' }}</td>
        </tr>
    </table>
    @if(!$stripeConfig['stripe_configured'])
    <div class="text-sm" style="color:var(--warning);margin-top:.6rem">
        Stripe is not fully configured. Set <code>GLASSBILLING_ENABLED=true</code>,
        <code>GLASSBILLING_MODE=stripe</code>, and <code>STRIPE_SECRET_KEY</code> to activate.
        Key values are never displayed here.
    </div>
    @endif
</div>

{{-- Counts --}}
<div class="grid grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem">
    @foreach([
        'Customers' => $counts['customers'],
        'Products' => $counts['products'],
        'Plans' => $counts['plans'],
        'Subscriptions' => $counts['subscriptions'],
        'Active subs' => $counts['active_subscriptions'],
        'Invoices' => $counts['invoices'],
        'Payments' => $counts['payments'],
        'Events' => $counts['events'],
    ] as $label => $value)
    <div class="card" style="text-align:center">
        <div style="font-size:1.6rem;font-weight:700;color:var(--text-h)">{{ $value }}</div>
        <div class="text-sm text-dim">{{ $label }}</div>
    </div>
    @endforeach
</div>
@endsection
