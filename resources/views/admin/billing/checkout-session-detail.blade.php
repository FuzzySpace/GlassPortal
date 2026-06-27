@extends('layouts.staff')

@section('title', 'Checkout Session')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div style="margin-bottom:1rem"><a href="{{ route('admin.billing.checkout-sessions') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Checkout Sessions</a></div>

<div class="card" style="margin-bottom:1.5rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="section-title" style="margin:0">Checkout Session</div>
        <span class="badge badge-{{ $session->isComplete() ? 'active' : ($session->isExpired() ? 'inactive' : 'pending') }}">{{ $session->status }}</span>
    </div>
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:35%">Provider session</td><td class="text-sm"><code>{{ $session->provider_session_id }}</code></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Provider customer</td><td class="text-sm"><code>{{ $session->provider_customer_id ?? '—' }}</code></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Provider subscription</td><td class="text-sm"><code>{{ $session->provider_subscription_id ?? '—' }}</code></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Customer</td><td class="text-sm">{{ $session->customer?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Plan</td><td class="text-sm">{{ $session->plan?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Subscription</td><td class="text-sm">{{ $session->subscription ? '#'.$session->subscription->id : '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Mode / payment</td><td class="text-sm">{{ $session->mode ?? '—' }} / {{ $session->payment_status ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Amount</td><td class="text-sm">{{ $session->amount_total ? '$'.number_format($session->amount_total/100, 2).' '.$session->currency : '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Completed</td><td class="text-sm">{{ $session->completed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
    </table>

    <div class="section-title" style="margin-top:1.25rem;margin-bottom:.4rem;font-size:.9rem">Payload <span class="text-dim text-sm">(secrets redacted)</span></div>
    <pre style="background:var(--surface);border:1px solid var(--border);border-radius:.375rem;padding:.6rem;font-size:.75rem;overflow:auto;color:var(--text-dim)">{{ json_encode($session->safePayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
@endsection
