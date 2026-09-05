@extends('layouts.customer')

@section('title', 'Invoice')

@php
    // Only a known, browser-safe hosted URL is surfaced — never the raw payload.
    $hostedUrl = data_get($invoice->metadata, 'hosted_invoice_url');
    $hostedUrl = (is_string($hostedUrl) && str_starts_with($hostedUrl, 'https://')) ? $hostedUrl : null;
@endphp

@section('content')
@include('portal.billing._nav')

<div style="margin-bottom:1rem"><a href="{{ route('portal.billing.invoices') }}" class="text-sm">← Invoices</a></div>

<div class="card" style="margin-bottom:1.5rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="section-title" style="margin:0">Invoice</div>
        <span class="badge badge-{{ $invoice->isPaid() ? 'active' : ($invoice->status === 'open' ? 'pending' : 'inactive') }}">{{ $invoice->status }}</span>
    </div>
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:35%">Invoice reference</td><td class="text-sm"><code>{{ $invoice->stripe_invoice_id ?? 'Invoice #'.$invoice->id }}</code></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Amount due</td><td class="text-sm">${{ number_format(($invoice->amount_due_cents ?? 0) / 100, 2) }} {{ $invoice->currency }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Amount paid</td><td class="text-sm">${{ number_format(($invoice->amount_paid_cents ?? 0) / 100, 2) }} {{ $invoice->currency }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Due</td><td class="text-sm">{{ $invoice->due_at?->format('Y-m-d') ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Paid</td><td class="text-sm">{{ $invoice->paid_at?->format('Y-m-d') ?? '—' }}</td></tr>
    </table>

    <div style="margin-top:1rem"><a href="{{ route('portal.billing.invoices.download', $invoice) }}" class="badge badge-active" style="text-decoration:none">Download PDF ↓</a></div>
    @if($hostedUrl)
        <div style="margin-top:1rem"><a href="{{ $hostedUrl }}" target="_blank" rel="noopener" class="badge badge-active" style="text-decoration:none">View / pay on Stripe →</a></div>
    @endif
</div>

<div class="section-title">Payments</div>
<div class="card" style="padding:0">
    <table style="width:100%">
        <thead><tr><th>Date</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead>
        <tbody>
            @forelse($invoice->payments as $pay)
            <tr>
                <td class="text-sm text-dim">{{ $pay->paid_at?->format('Y-m-d') ?? $pay->created_at?->format('Y-m-d') }}</td>
                <td class="text-sm">${{ number_format(($pay->amount_cents ?? 0) / 100, 2) }} {{ $pay->currency }}</td>
                <td><span class="badge badge-{{ $pay->isSucceeded() ? 'active' : ($pay->status === 'failed' ? 'inactive' : 'pending') }}">{{ $pay->status }}</span></td>
                <td class="text-sm text-dim"><code>{{ $pay->stripe_payment_intent_id ?? '—' }}</code></td>
            </tr>
            @empty
            <tr><td colspan="4" class="text-dim" style="text-align:center;padding:1.5rem">No payments recorded for this invoice.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
