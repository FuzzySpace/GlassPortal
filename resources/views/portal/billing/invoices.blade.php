@extends('layouts.customer')

@section('title', 'Invoices')

@section('content')
@include('portal.billing._nav')

<div class="page-header">
    <h2>Invoices</h2>
    <p>Your billing invoices. Amounts are shown in the invoice currency.</p>
</div>

<div class="card" style="padding:0">
    <table style="width:100%">
        <thead><tr><th>Invoice</th><th>Status</th><th>Amount due</th><th>Amount paid</th><th>Issued</th><th></th></tr></thead>
        <tbody>
            @forelse($invoices as $inv)
            <tr>
                <td class="text-sm text-dim"><code>{{ $inv->stripe_invoice_id ?? 'Invoice #'.$inv->id }}</code></td>
                <td><span class="badge badge-{{ $inv->isPaid() ? 'active' : ($inv->status === 'open' ? 'pending' : 'inactive') }}">{{ $inv->status }}</span></td>
                <td class="text-sm">${{ number_format(($inv->amount_due_cents ?? 0) / 100, 2) }} {{ $inv->currency }}</td>
                <td class="text-sm text-dim">${{ number_format(($inv->amount_paid_cents ?? 0) / 100, 2) }} {{ $inv->currency }}</td>
                <td class="text-sm text-dim">{{ $inv->created_at?->format('Y-m-d') }}</td>
                <td><a href="{{ route('portal.billing.invoices.show', $inv) }}" class="text-sm">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-dim" style="text-align:center;padding:2rem">You have no invoices.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($invoices->hasPages())<div style="margin-top:1rem">{{ $invoices->links() }}</div>@endif
@endsection
