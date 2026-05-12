@extends('layouts.staff')

@section('title', 'Invoice Approval')
@section('page-title', 'Invoice Approval')

@section('content')

<div style="margin-bottom:1rem">
    <a href="{{ route('admin.billing-approvals') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Back to Approvals</a>
</div>

@if(!$billingOk)
<div class="alert alert-warning" style="margin-bottom:1rem">
    {{ $billingError ?? 'Unable to load approval data.' }}
</div>
@elseif(!$approval)
<div class="alert alert-warning" style="margin-bottom:1rem">
    Approval <code>{{ $approvalId }}</code> not found.
</div>
@else

<div class="grid grid-2" style="margin-bottom:1.5rem">
    <div class="card">
        <div class="section-title" style="margin-bottom:.75rem">Invoice Details</div>
        <table style="width:100%">
            <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">ID</td><td class="text-sm">{{ $approval['id'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Invoice #</td><td class="text-sm">{{ $approval['invoice_number'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Amount</td>
                <td style="color:var(--text-h);font-weight:600;font-variant-numeric:tabular-nums">
                    @if(isset($approval['amount_usd']))
                        ${{ number_format($approval['amount_usd'], 2) }}
                    @else
                        —
                    @endif
                </td>
            </tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Status</td><td><span class="badge badge-{{ $approval['status'] ?? 'pending' }}">{{ $approval['status'] ?? '—' }}</span></td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Due Date</td><td class="text-sm">{{ $approval['due_date'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Created</td><td class="text-sm">{{ $approval['created_at'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Updated</td><td class="text-sm">{{ $approval['updated_at'] ?? '—' }}</td></tr>
        </table>
    </div>

    <div class="card">
        <div class="section-title" style="margin-bottom:.75rem">Customer</div>
        <table style="width:100%">
            <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">Customer ID</td><td class="text-sm">{{ $approval['customer_id'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Name</td><td style="color:var(--text-h)">{{ $approval['customer_name'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Email</td><td class="text-sm">{{ $approval['customer_email'] ?? '—' }}</td></tr>
        </table>

        <div class="section-title" style="margin-top:1.25rem;margin-bottom:.5rem">Approval Actions</div>
        <p class="text-sm text-dim">Approve / reject actions are coming in Phase 5.</p>
    </div>
</div>

@if(!empty($approval['line_items']))
<div class="section-title">Line Items</div>
<div class="card" style="padding:0;margin-bottom:1.5rem">
    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit Price</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($approval['line_items'] as $line)
            <tr>
                <td>{{ $line['description'] ?? '—' }}</td>
                <td class="text-sm">{{ $line['quantity'] ?? '—' }}</td>
                <td class="text-sm" style="font-variant-numeric:tabular-nums">{{ isset($line['unit_price']) ? '$'.number_format($line['unit_price'], 2) : '—' }}</td>
                <td class="text-sm" style="font-variant-numeric:tabular-nums">{{ isset($line['total']) ? '$'.number_format($line['total'], 2) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

@if(!empty($approval['notes']))
<div class="card">
    <div class="section-title" style="margin-bottom:.5rem">Notes</div>
    <p class="text-sm" style="margin:0;white-space:pre-wrap">{{ $approval['notes'] }}</p>
</div>
@endif

@endif
@endsection
