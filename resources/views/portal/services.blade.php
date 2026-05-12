@extends('layouts.customer')

@section('title', 'My Services')

@section('content')
<div class="page-header">
    <h2>My Services</h2>
    <p>All services associated with your account via GlassBilling.</p>
</div>

@php $offline = ($services['status'] ?? '') !== 'online'; @endphp

@if($offline)
<div class="alert alert-warning" style="margin-bottom:1rem">
    Service data is temporarily unavailable. Please try again later.
</div>
@endif

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Service</th>
                <th>Plan</th>
                <th>Status</th>
                <th>Billing Cycle</th>
                <th>Since</th>
            </tr>
        </thead>
        <tbody>
            @forelse($services['data'] ?? [] as $svc)
            <tr>
                <td style="font-weight:500;color:var(--text-h)">{{ $svc['product_name'] ?? '—' }}</td>
                <td class="text-sm">{{ $svc['plan_name'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $svc['status'] ?? 'pending' }}">{{ $svc['status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $svc['billing_cycle'] ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $svc['created_at'] ?? '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-dim" style="text-align:center;padding:2rem">
                    {{ $offline ? 'Unable to load services.' : 'No services found.' }}
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
