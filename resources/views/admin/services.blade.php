@extends('layouts.staff')

@section('title', 'Services')
@section('page-title', 'Customer Services')

@section('content')

@php $offline = ($services['status'] ?? '') !== 'online'; @endphp

@if($offline)
<div class="alert alert-warning" style="margin-bottom:1rem">
    GlassBilling is <strong>{{ $services['status'] ?? 'offline' }}</strong>.
    Service data cannot be loaded until the connector is configured and online.
</div>
@endif

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Customer</th>
                <th>Service</th>
                <th>Status</th>
                <th>Since</th>
            </tr>
        </thead>
        <tbody>
            @forelse($services['data'] ?? [] as $svc)
            <tr>
                <td>{{ $svc['customer_name'] ?? '—' }}</td>
                <td>{{ $svc['product_name'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $svc['status'] ?? 'pending' }}">{{ $svc['status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $svc['created_at'] ?? '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="4" class="text-dim" style="text-align:center;padding:2rem">
                    {{ $offline ? 'Connect GlassBilling to view services.' : 'No services found.' }}
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
