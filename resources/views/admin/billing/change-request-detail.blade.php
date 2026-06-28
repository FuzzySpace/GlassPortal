@extends('layouts.staff')

@section('title', 'Billing Change Request')
@section('page-title', 'GlassBilling')

@php $btn = 'padding:.4rem .85rem;border:none;border-radius:.375rem;font-size:.8rem;font-weight:500;cursor:pointer;color:#fff'; @endphp

@section('content')
@include('admin.billing._nav')

<div style="margin-bottom:1rem"><a href="{{ route('admin.billing.change-requests') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Change Requests</a></div>

@if(session('success'))<div class="alert alert-info" style="margin-bottom:1rem;color:var(--success)">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" style="margin-bottom:1rem">{{ session('error') }}</div>@endif

<div class="card" style="margin-bottom:1.5rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="section-title" style="margin:0">{{ $changeRequest->typeLabel() }}</div>
        <span class="badge badge-{{ in_array($changeRequest->status, ['approved','completed']) ? 'active' : (in_array($changeRequest->status, ['rejected','cancelled']) ? 'inactive' : 'pending') }}">{{ str_replace('_', ' ', $changeRequest->status) }}</span>
    </div>
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:35%">Reference</td><td class="text-sm"><code>{{ $changeRequest->request_key }}</code></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Customer</td><td class="text-sm">{{ $changeRequest->user?->name ?? '—' }} ({{ $changeRequest->user?->email ?? '—' }})</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Organization</td><td class="text-sm">{{ $changeRequest->organization?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Subscription</td><td class="text-sm">
            @if($changeRequest->subscription)<a href="{{ route('admin.billing.subscriptions') }}" style="color:var(--accent);text-decoration:none">{{ $changeRequest->subscription->plan?->name ?? 'Subscription #'.$changeRequest->billing_subscription_id }}</a>@else — @endif
        </td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Current plan</td><td class="text-sm">{{ $changeRequest->plan?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Requested plan</td><td class="text-sm">{{ $changeRequest->requestedPlan?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Submitted</td><td class="text-sm">{{ $changeRequest->requested_at?->format('Y-m-d H:i') ?? $changeRequest->created_at?->format('Y-m-d H:i') }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Reviewed by</td><td class="text-sm">{{ $changeRequest->reviewedBy?->name ?? '—' }} {{ $changeRequest->reviewed_at ? '('.$changeRequest->reviewed_at->format('Y-m-d H:i').')' : '' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Completed / cancelled</td><td class="text-sm">{{ $changeRequest->completed_at?->format('Y-m-d H:i') ?? $changeRequest->cancelled_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
    </table>

    @if($changeRequest->customer_message)
    <div class="section-title" style="margin-top:1.25rem;margin-bottom:.4rem;font-size:.9rem">Customer message</div>
    <p class="text-sm text-dim">{{ $changeRequest->customer_message }}</p>
    @endif

    @if($changeRequest->admin_notes)
    <div class="section-title" style="margin-top:1rem;margin-bottom:.4rem;font-size:.9rem">Internal notes</div>
    <pre style="background:var(--surface);border:1px solid var(--border);border-radius:.375rem;padding:.6rem;font-size:.78rem;white-space:pre-wrap;color:var(--text-dim)">{{ $changeRequest->admin_notes }}</pre>
    @endif

    {{-- Workflow actions — only valid transitions are offered. No Stripe / infra. --}}
    @unless($changeRequest->isTerminal())
    <div class="section-title" style="margin-top:1.25rem;margin-bottom:.5rem;font-size:.9rem">Workflow actions</div>
    <form method="POST" action="{{ route('admin.billing.change-requests.action', [$changeRequest, 'under-review']) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-start">
        @csrf
        <textarea name="admin_notes" placeholder="internal note (optional)" maxlength="2000" rows="2"
               style="flex:1;min-width:220px;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.4rem .6rem;border-radius:.375rem;font:inherit;font-size:.8rem"></textarea>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            @if($changeRequest->canTransitionTo('under_review'))
                <button type="submit" formaction="{{ route('admin.billing.change-requests.action', [$changeRequest, 'under-review']) }}" style="{{ $btn }};background:var(--accent-d)">Mark under review</button>
            @endif
            @if($changeRequest->canTransitionTo('approved'))
                <button type="submit" formaction="{{ route('admin.billing.change-requests.action', [$changeRequest, 'approve']) }}" style="{{ $btn }};background:var(--accent-d)">Approve</button>
            @endif
            @if($changeRequest->canTransitionTo('completed'))
                <button type="submit" formaction="{{ route('admin.billing.change-requests.action', [$changeRequest, 'complete']) }}" style="{{ $btn }};background:var(--success)">Complete</button>
            @endif
            @if($changeRequest->canTransitionTo('rejected'))
                <button type="submit" formaction="{{ route('admin.billing.change-requests.action', [$changeRequest, 'reject']) }}" style="{{ $btn }};background:var(--warning)">Reject</button>
            @endif
            @if($changeRequest->canTransitionTo('cancelled'))
                <button type="submit" formaction="{{ route('admin.billing.change-requests.action', [$changeRequest, 'cancel']) }}" style="{{ $btn }};background:var(--danger)">Cancel</button>
            @endif
        </div>
    </form>
    <div class="text-sm text-dim" style="margin-top:.5rem">Workflow only. These actions change the request's status and never call Stripe or touch infrastructure.</div>
    @else
    <div class="text-sm text-dim" style="margin-top:1.25rem">This request is closed ({{ str_replace('_', ' ', $changeRequest->status) }}).</div>
    @endunless
</div>
@endsection
