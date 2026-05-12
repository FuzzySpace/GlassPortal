@extends('layouts.customer')

@section('title', 'Overview')

@section('content')
<div class="page-header">
    <h2>Welcome back, {{ $user->name }}</h2>
    <p>Here's a summary of your Glasshouse services.</p>
</div>

@php $offline = ($services['status'] ?? '') === 'offline' || ($services['status'] ?? '') === 'unconfigured'; @endphp

@if($offline)
<div class="alert alert-warning" style="margin-bottom:1.5rem">
    Service data is temporarily unavailable. Please check back shortly.
</div>
@endif

<div class="grid grid-3" style="margin-bottom:1.5rem">
    <div class="card">
        <div class="card-title">Active Services</div>
        <div class="card-value">
            @if(!$offline)
                {{ count(array_filter($services['data'] ?? [], fn($s) => $s['status'] === 'active')) }}
            @else
                —
            @endif
        </div>
        <div class="text-sm text-dim" style="margin-top:.25rem">currently running</div>
    </div>
    <div class="card">
        <div class="card-title">Open Tickets</div>
        <div class="card-value">—</div>
        <div class="text-sm text-dim" style="margin-top:.25rem">support tickets <small>(Phase 4)</small></div>
    </div>
    <div class="card">
        <div class="card-title">Outstanding Balance</div>
        <div class="card-value">—</div>
        <div class="text-sm text-dim" style="margin-top:.25rem">invoices <small>(Phase 4)</small></div>
    </div>
</div>

<div class="section-title">My Services</div>
<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Service</th>
                <th>Status</th>
                <th>Since</th>
            </tr>
        </thead>
        <tbody>
            @forelse($services['data'] ?? [] as $svc)
            <tr>
                <td style="font-weight:500;color:var(--text-h)">{{ $svc['product_name'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $svc['status'] ?? 'pending' }}">{{ $svc['status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $svc['created_at'] ?? '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="3" class="text-dim" style="text-align:center;padding:2rem">
                    {{ $offline ? 'Unable to load services.' : 'No services yet.' }}
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
