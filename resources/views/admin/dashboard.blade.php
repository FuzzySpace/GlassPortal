@extends('layouts.staff')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')

@php
    $billingStatus  = $billingHealth['status'] ?? 'unconfigured';
    $billingDetail  = $billingHealth['detail'] ?? '';
    $billingLatency = $billingHealth['latency_ms'] ?? null;
@endphp

{{-- GlassBilling connection status card --}}
<div class="card" style="margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;padding:.85rem 1.25rem">
    <div style="display:flex;align-items:center;gap:.75rem">
        <strong style="color:var(--text-h)">GlassBilling</strong>
        <span class="badge badge-{{ $billingStatus }}">{{ $billingStatus }}</span>
        @if($billingDetail)
            <span class="text-dim text-sm">{{ $billingDetail }}</span>
        @endif
        @if($billingStatus !== 'online')
            <span class="text-dim text-sm">— Set <code>GLASSBILLING_BASE_URL</code> + <code>GLASSBILLING_API_TOKEN</code></span>
        @endif
    </div>
    @if($billingLatency !== null)
        <span class="text-dim text-sm">{{ $billingLatency }}ms</span>
    @endif
</div>

@if(!$billingOk && $billingError)
<div class="alert alert-warning" style="margin-bottom:1.5rem">{{ $billingError }}</div>
@endif

{{-- Summary tiles --}}
<div class="grid grid-4" style="margin-bottom:1.5rem">
    @if($billingOk && count($tiles ?? []))
        @foreach($tiles as $tile)
        <div class="card">
            <div class="card-title">{{ $tile['label'] ?? 'Metric' }}</div>
            <div class="card-value">{{ $tile['value'] ?? '—' }}</div>
            @if(!empty($tile['sub']))
                <div class="text-sm text-dim" style="margin-top:.25rem">{{ $tile['sub'] }}</div>
            @endif
        </div>
        @endforeach
    @else
        <div class="card">
            <div class="card-title">Services</div>
            <div class="card-value">{{ $servicesTotal ?? '—' }}</div>
            <div class="text-sm text-dim" style="margin-top:.25rem">customer services</div>
        </div>
        <div class="card">
            <div class="card-title">Provisioning</div>
            <div class="card-value">{{ $provisionTotal ?? '—' }}</div>
            <div class="text-sm text-dim" style="margin-top:.25rem">pending requests</div>
        </div>
        <div class="card">
            <div class="card-title">Invoice Approvals</div>
            <div class="card-value">{{ $approvalsTotal ?? '—' }}</div>
            <div class="text-sm text-dim" style="margin-top:.25rem">awaiting approval</div>
        </div>
        <div class="card">
            <div class="card-title">Connector</div>
            <div class="card-value" style="font-size:1rem;color:var(--text-dim)">
                {{ $billingStatus === 'unconfigured' ? 'Not set up' : ucfirst($billingStatus) }}
            </div>
            <div class="text-sm text-dim" style="margin-top:.25rem">GlassBilling status</div>
        </div>
    @endif
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
        <div class="text-sm text-dim">{{ $module['notes'] ?? '' }}</div>
    </div>
    @endforeach
</div>

@endsection
