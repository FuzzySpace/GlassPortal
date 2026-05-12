@extends('layouts.staff')

@section('title', 'Services')
@section('page-title', 'Customer Services')

@section('content')

@if(!$billingOk)
<div class="alert alert-warning" style="margin-bottom:1rem">
    @if($billingError)
        {{ $billingError }}
    @else
        GlassBilling is not configured. Set <code>GLASSBILLING_BASE_URL</code> and <code>GLASSBILLING_API_TOKEN</code>.
    @endif
</div>
@endif

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Product / Plan</th>
                <th>Status</th>
                <th>Billing</th>
                <th>Since</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($services as $svc)
            <tr>
                <td class="text-sm text-dim">{{ $svc['id'] ?? '—' }}</td>
                <td>{{ $svc['customer_name'] ?? $svc['customer_id'] ?? '—' }}</td>
                <td>
                    <span style="color:var(--text-h)">{{ $svc['product_name'] ?? '—' }}</span>
                    @if(!empty($svc['plan_name']))
                        <br><span class="text-sm text-dim">{{ $svc['plan_name'] }}</span>
                    @endif
                </td>
                <td><span class="badge badge-{{ $svc['status'] ?? 'unknown' }}">{{ $svc['status'] ?? '—' }}</span></td>
                <td><span class="badge badge-{{ $svc['billing_status'] ?? 'unknown' }}">{{ $svc['billing_status'] ?? '—' }}</span></td>
                <td class="text-sm text-dim">{{ $svc['created_at'] ?? '—' }}</td>
                <td>
                    <a href="{{ route('admin.services.show', $svc['id']) }}" style="color:var(--accent);text-decoration:none;font-size:.8125rem">View →</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="text-dim" style="text-align:center;padding:2rem">
                    {{ $billingOk ? 'No services found.' : 'Connect GlassBilling to view services.' }}
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if(!empty($meta['total']))
<div style="margin-top:.75rem;color:var(--text-dim);font-size:.8125rem">
    Showing {{ count($services) }} of {{ $meta['total'] }} services
    @if(!empty($meta['last_page']) && $meta['last_page'] > 1)
        (page {{ $meta['current_page'] ?? 1 }} of {{ $meta['last_page'] }})
    @endif
</div>
@endif

@endsection
