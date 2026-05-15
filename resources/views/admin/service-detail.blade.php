@extends('layouts.staff')

@section('title', 'Service Detail')
@section('page-title', 'Service Detail')

@section('content')

<div style="margin-bottom:1rem">
    <a href="{{ route('admin.services') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Back to Services</a>
</div>

@if(!$billingOk)
<div class="alert alert-warning" style="margin-bottom:1rem">
    {{ $billingError ?? 'Unable to load service data.' }}
</div>
@elseif(!$service)
<div class="alert alert-warning" style="margin-bottom:1rem">
    Service <code>{{ $serviceId }}</code> not found.
</div>
@else

{{-- Main service info --}}
<div class="grid grid-2" style="margin-bottom:1.5rem">
    <div class="card">
        <div class="section-title" style="margin-bottom:.75rem">Service Information</div>
        <table style="width:100%">
            <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">ID</td><td class="text-sm">{{ $service['id'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Product</td><td style="color:var(--text-h);font-weight:500">{{ $service['product_name'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Plan</td><td class="text-sm">{{ $service['plan_name'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Status</td><td><span class="badge badge-{{ $service['status'] ?? 'unknown' }}">{{ $service['status'] ?? '—' }}</span></td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Billing</td><td><span class="badge badge-{{ $service['billing_status'] ?? 'unknown' }}">{{ $service['billing_status'] ?? '—' }}</span></td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Cycle</td><td class="text-sm">{{ $service['billing_cycle'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Created</td><td class="text-sm">{{ $service['created_at'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Updated</td><td class="text-sm">{{ $service['updated_at'] ?? '—' }}</td></tr>
        </table>
    </div>

    <div class="card">
        <div class="section-title" style="margin-bottom:.75rem">Customer</div>
        <table style="width:100%">
            <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:40%">Customer ID</td><td class="text-sm">{{ $service['customer_id'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Name</td><td style="color:var(--text-h)">{{ $service['customer_name'] ?? '—' }}</td></tr>
            <tr><td class="text-dim text-sm" style="padding:.3rem 0">Email</td><td class="text-sm">{{ $service['customer_email'] ?? '—' }}</td></tr>
        </table>

        <div class="section-title" style="margin-top:1.25rem;margin-bottom:.75rem">Actions</div>
        <p class="text-sm text-dim">Write actions are not available in read-only mode (Phase 5+).</p>
    </div>
</div>

{{-- Timeline --}}
<div class="section-title">Activity Timeline</div>
<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Event</th>
                <th>Actor</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($timeline as $event)
            <tr>
                <td class="text-sm text-dim">{{ $event['created_at'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $event['type'] ?? 'info' }}">{{ $event['type'] ?? '—' }}</span></td>
                <td class="text-sm">{{ $event['actor'] ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $event['notes'] ?? '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="text-dim" style="text-align:center;padding:2rem">No timeline events.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@endif
@endsection
