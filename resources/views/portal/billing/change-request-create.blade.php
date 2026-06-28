@extends('layouts.customer')

@section('title', 'New Billing Request')

@php
    $typeLabels = [
        'cancel_subscription'    => 'Cancel a subscription',
        'change_plan'            => 'Change plan',
        'update_billing_details' => 'Update billing details',
        'billing_support'        => 'Billing support / question',
        'pause_service'          => 'Pause a service',
        'resume_service'         => 'Resume a service',
    ];
    $selectedType = old('request_type', request('type'));
    $selectedSub  = old('billing_subscription_id', request('subscription'));
    $input = 'width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.5rem .65rem;border-radius:.375rem;font:inherit;font-size:.9rem';
@endphp

@section('content')
@include('portal.billing._nav')

<div style="margin-bottom:1rem"><a href="{{ route('portal.billing.change-requests') }}" class="text-sm">← Billing Requests</a></div>

<div class="page-header">
    <h2>New Billing Request</h2>
    <p>Tell us what you'd like to change. This creates a request for our team to review — nothing changes on your account until it's processed.</p>
</div>

@if($errors->any())
    <div class="alert alert-warning" style="margin-bottom:1rem">{{ $errors->first() }}</div>
@endif

<div class="card" style="max-width:640px">
    <form method="POST" action="{{ route('portal.billing.change-requests.store') }}">
        @csrf

        <label style="display:block;margin-bottom:1rem">
            <span class="card-title">Request type</span>
            <select name="request_type" style="{{ $input }}" required>
                <option value="">— choose —</option>
                @foreach($types as $type)
                    <option value="{{ $type }}" @selected($selectedType === $type)>{{ $typeLabels[$type] ?? $type }}</option>
                @endforeach
            </select>
        </label>

        <label style="display:block;margin-bottom:1rem">
            <span class="card-title">Subscription (if applicable)</span>
            <select name="billing_subscription_id" style="{{ $input }}">
                <option value="">— none —</option>
                @foreach($subscriptions as $sub)
                    <option value="{{ $sub->id }}" @selected((string) $selectedSub === (string) $sub->id)>{{ $sub->plan?->name ?? 'Subscription #'.$sub->id }} ({{ $sub->status }})</option>
                @endforeach
            </select>
        </label>

        <label style="display:block;margin-bottom:1rem">
            <span class="card-title">Change to plan (for plan changes)</span>
            <select name="requested_plan_id" style="{{ $input }}">
                <option value="">— none —</option>
                @foreach($plans as $plan)
                    <option value="{{ $plan->id }}" @selected((string) old('requested_plan_id') === (string) $plan->id)>{{ $plan->name }} — {{ $plan->priceLabel() }}</option>
                @endforeach
            </select>
        </label>

        <label style="display:block;margin-bottom:1rem">
            <span class="card-title">Message</span>
            <textarea name="customer_message" rows="4" maxlength="2000" placeholder="Add any details that will help us process your request" style="{{ $input }}">{{ old('customer_message') }}</textarea>
        </label>

        <button type="submit" style="padding:.55rem 1.1rem;border:none;border-radius:.375rem;font:inherit;font-weight:600;cursor:pointer;background:var(--accent-d);color:#fff">Submit request</button>
    </form>
</div>
@endsection
