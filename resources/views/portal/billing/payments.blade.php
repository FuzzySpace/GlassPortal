@extends('layouts.customer')

@section('title', 'Payments')

@section('content')
@include('portal.billing._nav')

<div class="page-header">
    <h2>Payment History</h2>
    <p>Your payments and the cards on file. We never store full card numbers — only a safe summary.</p>
</div>

@if($paymentMethods->isNotEmpty())
<div class="card" style="margin-bottom:1.5rem">
    <div class="section-title">Payment methods</div>
    @foreach($paymentMethods as $pm)
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--border)">
            <span>{{ $pm->label() }}</span>
            <span class="text-sm text-dim">
                @if($pm->exp_month && $pm->exp_year)exp {{ str_pad((string) $pm->exp_month, 2, '0', STR_PAD_LEFT) }}/{{ $pm->exp_year }}@endif
                @if($pm->is_default)<span class="badge badge-active" style="margin-left:.5rem">default</span>@endif
            </span>
        </div>
    @endforeach
</div>
@endif

<div class="card" style="padding:0">
    <table style="width:100%">
        <thead><tr><th>Date</th><th>Amount</th><th>Status</th><th>Invoice</th><th>Reference</th></tr></thead>
        <tbody>
            @forelse($payments as $pay)
            <tr>
                <td class="text-sm text-dim">{{ $pay->paid_at?->format('Y-m-d') ?? $pay->created_at?->format('Y-m-d') }}</td>
                <td class="text-sm">${{ number_format(($pay->amount_cents ?? 0) / 100, 2) }} {{ $pay->currency }}</td>
                <td><span class="badge badge-{{ $pay->isSucceeded() ? 'active' : ($pay->status === 'failed' ? 'inactive' : 'pending') }}">{{ $pay->status }}</span></td>
                <td class="text-sm">
                    @if($pay->invoice)
                        <a href="{{ route('portal.billing.invoices.show', $pay->invoice) }}">{{ $pay->invoice->stripe_invoice_id ?? 'Invoice #'.$pay->invoice->id }}</a>
                    @else <span class="text-dim">—</span> @endif
                </td>
                <td class="text-sm text-dim"><code>{{ $pay->stripe_payment_intent_id ?? '—' }}</code></td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:2rem">You have no payments yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($payments->hasPages())<div style="margin-top:1rem">{{ $payments->links() }}</div>@endif
@endsection
