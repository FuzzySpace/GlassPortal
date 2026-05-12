@extends('layouts.staff')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')

{{-- GlassBilling connection status --}}
@php
    $billingStatus = $billingHealth['status'] ?? 'unconfigured';
    $billingDetail = $billingHealth['detail'] ?? '';
    $summary       = $billingSummary ?? [];
@endphp

<div class="alert {{ $billingStatus === 'online' ? 'alert-info' : 'alert-warning' }}" style="margin-bottom:1.5rem">
    <strong>GlassBilling:</strong>
    <span class="badge badge-{{ $billingStatus }}">{{ $billingStatus }}</span>
    @if($billingDetail)
        &nbsp;<span class="text-dim text-sm">{{ $billingDetail }}</span>
    @endif
    @if($billingStatus !== 'online')
        — Set <code>GLASSBILLING_API_URL</code> and <code>GLASSBILLING_API_TOKEN</code> to connect.
    @endif
</div>

{{-- Summary cards --}}
<div class="grid grid-4" style="margin-bottom:1.5rem">
    <div class="card">
        <div class="card-title">Active Subscriptions</div>
        <div class="card-value">{{ $summary['active_subscriptions'] ?? '—' }}</div>
        <div class="card-sub">via GlassBilling</div>
    </div>
    <div class="card">
        <div class="card-title">MRR (USD)</div>
        <div class="card-value">{{ $summary['mrr_usd'] !== null ? '$'.number_format($summary['mrr_usd'], 2) : '—' }}</div>
        <div class="card-sub">monthly recurring</div>
    </div>
    <div class="card">
        <div class="card-title">Open Invoices</div>
        <div class="card-value">{{ $summary['open_invoices'] ?? '—' }}</div>
        <div class="card-sub">awaiting payment</div>
    </div>
    <div class="card">
        <div class="card-title">Pending Approvals</div>
        <div class="card-value">{{ $summary['pending_approvals'] ?? '—' }}</div>
        <div class="card-sub">provisioning requests</div>
    </div>
</div>

{{-- Module quick-links --}}
<div class="section-title">Ecosystem Modules</div>
<div class="grid grid-3">
    @foreach(config('glasshouse.modules', []) as $key => $module)
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.35rem">
            <span style="font-weight:600;color:var(--text-h);font-size:.875rem">{{ $module['display_name'] }}</span>
            @if(!($module['enabled'] ?? false))
                <span class="badge badge-disabled">disabled</span>
            @elseif(empty($module['base_url']))
                <span class="badge badge-unconfigured">not configured</span>
            @elseif($key === 'glassbilling')
                <span class="badge badge-{{ $billingStatus }}">{{ $billingStatus }}</span>
            @else
                <span class="badge badge-stub">stub</span>
            @endif
        </div>
        <div class="text-sm text-dim">{{ $module['description'] ?? '' }}</div>
    </div>
    @endforeach
</div>

@endsection
