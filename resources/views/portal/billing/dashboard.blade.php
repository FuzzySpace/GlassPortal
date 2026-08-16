@extends('layouts.customer')

@section('title', 'Billing')

@section('content')
@include('portal.billing._nav')

<div class="page-header">
    <h2>Billing Overview</h2>
    <form method="POST" action="{{ route('portal.billing.manage') }}" style="display:inline;margin-left:1rem">@csrf<button type="submit" class="badge badge-active" style="border:none;cursor:pointer;text-decoration:none">Manage Payment Method →</button></form>
    <p>View your subscriptions, invoices, payments and service requests. To make a change, submit a billing request — our team reviews every request.</p>
</div>

@unless($data['hasBillingScope'])
    <div class="card" style="text-align:center;padding:2rem;color:var(--text-dim)">
        You don't have any billing records yet. When you start a subscription it will appear here.
        <div style="margin-top:1rem"><a href="{{ route('portal.billing.plans') }}" class="badge badge-active" style="text-decoration:none">Browse plans →</a></div>
    </div>
@else

    @forelse($data['warnings'] as $warning)
        <div class="alert alert-warning" style="margin-bottom:.75rem">{{ $warning }}</div>
    @empty
    @endforelse

    <div class="grid grid-3" style="margin-bottom:1.5rem">
        <div class="card">
            <div class="card-title">Active subscriptions</div>
            <div class="card-value">{{ $data['activeSubscriptions']->count() }}</div>
        </div>
        <div class="card">
            <div class="card-title">Past-due subscriptions</div>
            <div class="card-value">{{ $data['pastDueSubscriptions']->count() }}</div>
        </div>
        <div class="card">
            <div class="card-title">Open requests</div>
            <div class="card-value">{{ $data['openChangeRequests']->count() }}</div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-bottom:1.5rem">
        {{-- Active subscriptions --}}
        <div class="card">
            <div class="section-title">Subscriptions</div>
            @forelse($data['activeSubscriptions']->take(5) as $sub)
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--border)">
                    <a href="{{ route('portal.billing.subscriptions.show', $sub) }}">{{ $sub->plan?->name ?? 'Subscription #'.$sub->id }}</a>
                    <span class="badge badge-{{ $sub->isLive() ? 'active' : 'pending' }}">{{ $sub->status }}</span>
                </div>
            @empty
                <p class="text-dim text-sm">No active subscriptions.</p>
            @endforelse
            <div style="margin-top:.75rem"><a href="{{ route('portal.billing.subscriptions') }}" class="text-sm">All subscriptions →</a></div>
        </div>

        {{-- Entitlements --}}
        <div class="card">
            <div class="section-title">Active services</div>
            @forelse($data['entitlements']->take(5) as $ent)
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--border)">
                    <span>{{ $ent->name }}</span>
                    <span class="badge badge-{{ $ent->isActive() ? 'active' : 'pending' }}">{{ $ent->status }}</span>
                </div>
            @empty
                <p class="text-dim text-sm">No active services yet.</p>
            @endforelse
            <div style="margin-top:.75rem"><a href="{{ route('portal.entitlements') }}" class="text-sm">All entitlements →</a></div>
        </div>
    </div>

    <div class="grid grid-2" style="margin-bottom:1.5rem">
        {{-- Recent invoices --}}
        <div class="card">
            <div class="section-title">Recent invoices</div>
            @forelse($data['recentInvoices'] as $inv)
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--border)">
                    <a href="{{ route('portal.billing.invoices.show', $inv) }}" class="text-sm">{{ $inv->stripe_invoice_id ?? 'Invoice #'.$inv->id }}</a>
                    <span class="text-sm text-dim">${{ number_format(($inv->amount_due_cents ?? 0) / 100, 2) }} {{ $inv->currency }}</span>
                    <span class="badge badge-{{ $inv->isPaid() ? 'active' : 'pending' }}">{{ $inv->status }}</span>
                </div>
            @empty
                <p class="text-dim text-sm">No invoices yet.</p>
            @endforelse
            <div style="margin-top:.75rem"><a href="{{ route('portal.billing.invoices') }}" class="text-sm">All invoices →</a></div>
        </div>

        {{-- Recent payments --}}
        <div class="card">
            <div class="section-title">Recent payments</div>
            @forelse($data['recentPayments'] as $pay)
                <div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--border)">
                    <span class="text-sm text-dim">{{ $pay->paid_at?->format('Y-m-d') ?? $pay->created_at?->format('Y-m-d') }}</span>
                    <span class="text-sm">${{ number_format(($pay->amount_cents ?? 0) / 100, 2) }} {{ $pay->currency }}</span>
                    <span class="badge badge-{{ $pay->isSucceeded() ? 'active' : ($pay->status === 'failed' ? 'inactive' : 'pending') }}">{{ $pay->status }}</span>
                </div>
            @empty
                <p class="text-dim text-sm">No payments yet.</p>
            @endforelse
            <div style="margin-top:.75rem"><a href="{{ route('portal.billing.payments') }}" class="text-sm">Payment history →</a></div>
        </div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
            <div class="section-title" style="margin:0">Billing requests</div>
            <a href="{{ route('portal.billing.change-requests.create') }}" class="badge badge-active" style="text-decoration:none">New request</a>
        </div>
        @forelse($data['openChangeRequests']->take(5) as $req)
            <div style="display:flex;justify-content:space-between;align-items:center;padding:.4rem 0;border-bottom:1px solid var(--border)">
                <a href="{{ route('portal.billing.change-requests.show', $req) }}" class="text-sm">{{ $req->typeLabel() }}</a>
                <span class="badge badge-pending">{{ str_replace('_', ' ', $req->status) }}</span>
            </div>
        @empty
            <p class="text-dim text-sm">You have no open billing requests.</p>
        @endforelse
        <div style="margin-top:.75rem"><a href="{{ route('portal.billing.change-requests') }}" class="text-sm">All requests →</a></div>
    </div>

@endunless
@endsection
