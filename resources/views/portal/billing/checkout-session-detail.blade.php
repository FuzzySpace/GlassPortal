@extends('layouts.customer')

@section('title', 'Checkout Session')

@section('content')
@include('portal.billing._nav')

<div style="margin-bottom:1rem"><a href="{{ route('portal.billing.checkout-sessions') }}" class="text-sm">← Checkout History</a></div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="section-title" style="margin:0">Checkout Session</div>
        <span class="badge badge-{{ $session->isComplete() ? 'active' : ($session->isExpired() ? 'inactive' : 'pending') }}">{{ $session->status }}</span>
    </div>
    {{-- Safe fields only — never the raw provider payload or metadata. --}}
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:35%">Product</td><td class="text-sm">{{ $session->product?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Plan</td><td class="text-sm">{{ $session->plan?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Payment status</td><td class="text-sm">{{ $session->payment_status ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Amount</td><td class="text-sm">@if($session->amount_total)${{ number_format($session->amount_total / 100, 2) }} {{ $session->currency }}@else — @endif</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Started</td><td class="text-sm">{{ $session->created_at?->format('Y-m-d H:i') }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Completed</td><td class="text-sm">{{ $session->completed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Expires</td><td class="text-sm">{{ $session->expires_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
    </table>
</div>
@endsection
