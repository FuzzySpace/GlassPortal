@extends('layouts.staff')

@section('title', 'Provisioning')
@section('page-title', 'Provisioning Requests')

@section('content')

@php $offline = ($requests['status'] ?? '') !== 'online'; @endphp

@if($offline)
<div class="alert alert-warning" style="margin-bottom:1rem">
    GlassBilling is <strong>{{ $requests['status'] ?? 'offline' }}</strong>.
    Provisioning request data requires the GlassBilling connector.
</div>
@endif

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Product</th>
                <th>Status</th>
                <th>Requested</th>
            </tr>
        </thead>
        <tbody>
            @forelse($requests['data'] ?? [] as $req)
            <tr>
                <td class="text-sm text-dim">{{ $req['id'] ?? '—' }}</td>
                <td>{{ $req['customer_name'] ?? '—' }}</td>
                <td>{{ $req['product_name'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $req['status'] ?? 'pending' }}">{{ $req['status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $req['created_at'] ?? '—' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="text-dim" style="text-align:center;padding:2rem">
                    {{ $offline ? 'Connect GlassBilling to view provisioning requests.' : 'No pending requests.' }}
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@endsection
