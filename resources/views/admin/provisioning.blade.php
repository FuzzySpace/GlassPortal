@extends('layouts.staff')

@section('title', 'Provisioning')
@section('page-title', 'Provisioning Requests')

@section('content')

@if(!$billingOk)
<div class="alert alert-warning" style="margin-bottom:1rem">
    @if($billingError)
        {{ $billingError }}
    @else
        GlassBilling is not configured. Provisioning data requires the connector.
    @endif
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
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($requests as $req)
            <tr>
                <td class="text-sm text-dim">{{ $req['id'] ?? '—' }}</td>
                <td>{{ $req['customer_name'] ?? $req['customer_id'] ?? '—' }}</td>
                <td>{{ $req['product_name'] ?? '—' }}</td>
                <td><span class="badge badge-{{ $req['status'] ?? 'pending' }}">{{ $req['status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $req['created_at'] ?? '—' }}</td>
                <td>
                    <a href="{{ route('admin.provisioning.show', $req['id']) }}" style="color:var(--accent);text-decoration:none;font-size:.8125rem">View →</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="text-dim" style="text-align:center;padding:2rem">
                    {{ $billingOk ? 'No pending requests.' : 'Connect GlassBilling to view provisioning requests.' }}
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if(!empty($meta['total']))
<div style="margin-top:.75rem;color:var(--text-dim);font-size:.8125rem">
    Showing {{ count($requests) }} of {{ $meta['total'] }} requests
</div>
@endif

@endsection
