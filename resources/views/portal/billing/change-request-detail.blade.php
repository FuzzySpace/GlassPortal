@extends('layouts.customer')

@section('title', 'Billing Request')

@section('content')
@include('portal.billing._nav')

<div style="margin-bottom:1rem"><a href="{{ route('portal.billing.change-requests') }}" class="text-sm">← Billing Requests</a></div>

<div class="card" style="margin-bottom:1.5rem;max-width:720px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="section-title" style="margin:0">{{ $changeRequest->typeLabel() }}</div>
        <span class="badge badge-{{ in_array($changeRequest->status, ['approved','completed']) ? 'active' : (in_array($changeRequest->status, ['rejected','cancelled']) ? 'inactive' : 'pending') }}">{{ str_replace('_', ' ', $changeRequest->status) }}</span>
    </div>
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:35%">Reference</td><td class="text-sm"><code>{{ $changeRequest->request_key }}</code></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Subscription</td><td class="text-sm">{{ $changeRequest->subscription?->plan?->name ?? ($changeRequest->billing_subscription_id ? 'Subscription #'.$changeRequest->billing_subscription_id : '—') }}</td></tr>
        @if($changeRequest->requestedPlan)
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Requested plan</td><td class="text-sm">{{ $changeRequest->requestedPlan->name }} — {{ $changeRequest->requestedPlan->priceLabel() }}</td></tr>
        @endif
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Submitted</td><td class="text-sm">{{ $changeRequest->requested_at?->format('Y-m-d H:i') ?? $changeRequest->created_at?->format('Y-m-d H:i') }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Reviewed</td><td class="text-sm">{{ $changeRequest->reviewed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Completed</td><td class="text-sm">{{ $changeRequest->completed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
    </table>

    @if($changeRequest->customer_message)
    <div class="section-title" style="margin-top:1.25rem;margin-bottom:.4rem;font-size:.9rem">Your message</div>
    <p class="text-sm text-dim">{{ $changeRequest->customer_message }}</p>
    @endif

    @if($changeRequest->isCustomerCancellable())
    <form method="POST" action="{{ route('portal.billing.change-requests.cancel', $changeRequest) }}" style="margin-top:1.25rem" onsubmit="return confirm('Cancel this request?')">
        @csrf
        <button type="submit" style="padding:.45rem .9rem;border:none;border-radius:.375rem;font:inherit;font-size:.85rem;cursor:pointer;background:var(--warning);color:#fff">Cancel this request</button>
    </form>
    @endif
</div>
@endsection
