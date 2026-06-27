@extends('layouts.staff')

@section('title', 'Entitlement')
@section('page-title', 'GlassBilling')

@php $btn = 'padding:.4rem .85rem;border:none;border-radius:.375rem;font-size:.8rem;font-weight:500;cursor:pointer;color:#fff'; @endphp

@section('content')
@include('admin.billing._nav')

<div style="margin-bottom:1rem"><a href="{{ route('admin.billing.entitlements') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Entitlements</a></div>

@if(session('success'))<div class="alert alert-info" style="margin-bottom:1rem;color:var(--success)">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" style="margin-bottom:1rem">{{ session('error') }}</div>@endif

<div class="card" style="margin-bottom:1.5rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="section-title" style="margin:0">{{ $entitlement->name }}</div>
        <span class="badge badge-{{ $entitlement->isActive() ? 'active' : ($entitlement->isTerminal() ? 'inactive' : 'pending') }}">{{ $entitlement->status }}</span>
    </div>
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:35%">Key</td><td class="text-sm"><code>{{ $entitlement->entitlement_key }}</code></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Customer</td><td class="text-sm">
            @if($entitlement->customer)<a href="{{ route('admin.billing.customers.show', $entitlement->customer) }}" style="color:var(--accent);text-decoration:none">{{ $entitlement->customer->name ?? 'Customer #'.$entitlement->billing_customer_id }}</a>@else — @endif
        </td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Organization</td><td class="text-sm">{{ $entitlement->organization?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Product / Plan</td><td class="text-sm">{{ $entitlement->product?->name ?? '—' }} / {{ $entitlement->plan?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Subscription</td><td class="text-sm">{{ $entitlement->subscription ? '#'.$entitlement->subscription->id : '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Service type</td><td class="text-sm">{{ $entitlement->service_type ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Quantity</td><td class="text-sm">{{ $entitlement->quantity }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Current period</td><td class="text-sm">{{ $entitlement->current_period_start?->format('Y-m-d') ?? '—' }} → {{ $entitlement->current_period_end?->format('Y-m-d') ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Provisionable</td><td class="text-sm">{{ $entitlement->canProvision() ? 'yes' : 'no' }}</td></tr>
    </table>

    {{-- Controlled lifecycle actions — only valid transitions are offered --}}
    <div class="section-title" style="margin-top:1.25rem;margin-bottom:.5rem;font-size:.9rem">Lifecycle actions</div>
    <form method="POST" action="{{ route('admin.billing.entitlements.action', [$entitlement, 'suspend']) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        @csrf
        <input type="text" name="reason" placeholder="reason (optional)" maxlength="255"
               style="background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.35rem .6rem;border-radius:.375rem;font-size:.8rem;min-width:200px">
        @if($entitlement->canSuspend())
            <button type="submit" formaction="{{ route('admin.billing.entitlements.action', [$entitlement, 'suspend']) }}" style="{{ $btn }};background:var(--warning)">Suspend</button>
        @endif
        @if($entitlement->canReactivate())
            <button type="submit" formaction="{{ route('admin.billing.entitlements.action', [$entitlement, 'reactivate']) }}" style="{{ $btn }};background:var(--accent-d)">Reactivate</button>
        @endif
        @if($entitlement->canTransitionTo('provisioning_pending'))
            <button type="submit" formaction="{{ route('admin.billing.entitlements.action', [$entitlement, 'provisioning-pending']) }}" style="{{ $btn }};background:var(--accent-d)">Mark provisioning pending</button>
        @endif
        @if($entitlement->canTransitionTo('provisioning_failed'))
            <button type="submit" formaction="{{ route('admin.billing.entitlements.action', [$entitlement, 'provisioning-failed']) }}" style="{{ $btn }};background:var(--danger)">Mark provisioning failed</button>
        @endif
        @if($entitlement->canTransitionTo('active'))
            <button type="submit" formaction="{{ route('admin.billing.entitlements.action', [$entitlement, 'reactivate']) }}" style="{{ $btn }};background:var(--accent-d)">Activate</button>
        @endif
        @if($entitlement->canCancel())
            <button type="submit" formaction="{{ route('admin.billing.entitlements.action', [$entitlement, 'cancel']) }}" style="{{ $btn }};background:var(--warning)">Cancel</button>
        @endif
        @if($entitlement->canTerminate())
            <button type="submit" formaction="{{ route('admin.billing.entitlements.action', [$entitlement, 'terminate']) }}" style="{{ $btn }};background:var(--danger)" onclick="return confirm('Terminate this entitlement?')">Terminate</button>
        @endif
    </form>
    <div class="text-sm text-dim" style="margin-top:.5rem">Actions only change billing entitlement state and are audited. They never provision or touch infrastructure.</div>
</div>

<div class="section-title">Event History</div>
<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Event</th><th>From → To</th><th>Actor</th><th>Reason</th><th>When</th></tr></thead>
        <tbody>
            @forelse($entitlement->events as $ev)
            <tr>
                <td class="text-sm" style="color:var(--text-h)">{{ $ev->event_type }}</td>
                <td class="text-sm text-dim">{{ $ev->previous_status ?? '—' }} → {{ $ev->new_status ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $ev->actor_type ? class_basename($ev->actor_type).' #'.$ev->actor_id : 'system' }}</td>
                <td class="text-sm text-dim">{{ $ev->reason ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $ev->created_at?->format('Y-m-d H:i') }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:1.5rem">No events.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
