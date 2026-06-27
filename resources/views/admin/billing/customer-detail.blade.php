@extends('layouts.staff')

@section('title', 'Billing Customer')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div style="margin-bottom:1rem">
    <a href="{{ route('admin.billing.customers') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Billing Customers</a>
</div>

<div class="card" style="margin-bottom:1.5rem">
    <div class="section-title" style="margin-bottom:.75rem">{{ $customer->name ?? 'Customer #'.$customer->id }}</div>
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">Email</td><td class="text-sm">{{ $customer->email ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Status</td><td><span class="badge badge-{{ $customer->status === 'active' ? 'active' : 'inactive' }}">{{ $customer->status }}</span></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Organization</td><td class="text-sm">{{ $customer->organization?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Stripe customer</td><td class="text-sm">{{ $customer->isLinkedToStripe() ? 'linked' : 'not linked' }}</td></tr>
    </table>
</div>

<div class="section-title">Subscriptions</div>
<div class="card" style="padding:0;margin-bottom:1.5rem">
    <table>
        <thead><tr><th>Plan</th><th>Status</th><th>Period end</th><th>Cancels?</th></tr></thead>
        <tbody>
            @forelse($customer->subscriptions as $s)
            <tr>
                <td>{{ $s->plan?->name ?? '—' }}</td>
                <td><span class="badge badge-{{ $s->isLive() ? 'active' : 'inactive' }}">{{ $s->status }}</span></td>
                <td class="text-sm text-dim">{{ $s->current_period_end?->format('Y-m-d') ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $s->cancel_at_period_end ? 'yes' : 'no' }}</td>
            </tr>
            @empty<tr><td colspan="4" class="text-dim" style="text-align:center;padding:1.5rem">No subscriptions.</td></tr>@endforelse
        </tbody>
    </table>
</div>

<div class="section-title">Invoices</div>
<div class="card" style="padding:0;margin-bottom:1.5rem">
    <table>
        <thead><tr><th>Status</th><th>Due</th><th>Paid</th><th>Paid at</th></tr></thead>
        <tbody>
            @forelse($customer->invoices as $i)
            <tr>
                <td><span class="badge badge-{{ $i->isPaid() ? 'active' : 'pending' }}">{{ $i->status }}</span></td>
                <td class="text-sm" style="font-variant-numeric:tabular-nums">${{ number_format($i->amount_due_cents/100, 2) }}</td>
                <td class="text-sm" style="font-variant-numeric:tabular-nums">${{ number_format($i->amount_paid_cents/100, 2) }}</td>
                <td class="text-sm text-dim">{{ $i->paid_at?->format('Y-m-d') ?? '—' }}</td>
            </tr>
            @empty<tr><td colspan="4" class="text-dim" style="text-align:center;padding:1.5rem">No invoices.</td></tr>@endforelse
        </tbody>
    </table>
</div>

<div class="section-title">Payment Methods</div>
<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Method</th><th>Expiry</th><th>Default</th></tr></thead>
        <tbody>
            @forelse($customer->paymentMethods as $pm)
            <tr>
                <td class="text-sm">{{ $pm->label() }}</td>
                <td class="text-sm text-dim">{{ $pm->exp_month && $pm->exp_year ? str_pad((string)$pm->exp_month,2,'0',STR_PAD_LEFT).'/'.$pm->exp_year : '—' }}</td>
                <td class="text-sm text-dim">{{ $pm->is_default ? '★' : '' }}</td>
            </tr>
            @empty<tr><td colspan="3" class="text-dim" style="text-align:center;padding:1.5rem">No payment methods.</td></tr>@endforelse
        </tbody>
    </table>
</div>
@endsection
