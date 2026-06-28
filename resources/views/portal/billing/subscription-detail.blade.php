@extends('layouts.customer')

@section('title', 'Subscription')

@section('content')
@include('portal.billing._nav')

<div style="margin-bottom:1rem"><a href="{{ route('portal.billing.subscriptions') }}" class="text-sm">← Subscriptions</a></div>

<div class="card" style="margin-bottom:1.5rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="section-title" style="margin:0">{{ $subscription->plan?->name ?? 'Subscription #'.$subscription->id }}</div>
        <span class="badge badge-{{ $subscription->isLive() ? 'active' : ($subscription->status === 'past_due' ? 'pending' : 'inactive') }}">{{ $subscription->status }}</span>
    </div>
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:35%">Product</td><td class="text-sm">{{ $subscription->plan?->product?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Plan</td><td class="text-sm">{{ $subscription->plan?->name ?? '—' }} @if($subscription->plan) ({{ $subscription->plan->priceLabel() }}) @endif</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Billing period</td><td class="text-sm">{{ $subscription->current_period_start?->format('Y-m-d') ?? '—' }} → {{ $subscription->current_period_end?->format('Y-m-d') ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Auto-renew</td><td class="text-sm">{{ $subscription->cancel_at_period_end ? 'Cancels at period end' : 'Yes' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Reference</td><td class="text-sm"><code>{{ $subscription->stripe_subscription_id ?? '—' }}</code></td></tr>
    </table>

    <div style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="{{ route('portal.billing.change-requests.create', ['subscription' => $subscription->id, 'type' => 'change_plan']) }}" class="badge badge-active" style="text-decoration:none">Request plan change</a>
        <a href="{{ route('portal.billing.change-requests.create', ['subscription' => $subscription->id, 'type' => 'cancel_subscription']) }}" class="badge badge-pending" style="text-decoration:none">Request cancellation</a>
    </div>
</div>

@if($subscription->serviceEntitlements->isNotEmpty())
<div class="section-title">Related services</div>
<div class="card" style="padding:0;margin-bottom:1.5rem">
    <table style="width:100%">
        <thead><tr><th>Service</th><th>Status</th></tr></thead>
        <tbody>
            @foreach($subscription->serviceEntitlements as $ent)
            <tr><td>{{ $ent->name }}</td><td><span class="badge badge-{{ $ent->isActive() ? 'active' : 'pending' }}">{{ $ent->status }}</span></td></tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

<div class="section-title">Related invoices</div>
<div class="card" style="padding:0">
    <table style="width:100%">
        <thead><tr><th>Invoice</th><th>Status</th><th>Amount</th><th></th></tr></thead>
        <tbody>
            @forelse($invoices as $inv)
            <tr>
                <td class="text-sm text-dim"><code>{{ $inv->stripe_invoice_id ?? 'Invoice #'.$inv->id }}</code></td>
                <td><span class="badge badge-{{ $inv->isPaid() ? 'active' : 'pending' }}">{{ $inv->status }}</span></td>
                <td class="text-sm">${{ number_format(($inv->amount_due_cents ?? 0) / 100, 2) }} {{ $inv->currency }}</td>
                <td><a href="{{ route('portal.billing.invoices.show', $inv) }}" class="text-sm">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="4" class="text-dim" style="text-align:center;padding:1.5rem">No invoices for this subscription's account yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
