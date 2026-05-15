@extends('layouts.staff')

@section('title', 'Customers')
@section('page-title', 'Customers')

@section('content')

@if(!$billingConfigured)
<div class="alert alert-warning" style="margin-bottom:1rem">
    GlassBilling is not configured. Customer data shows local records only.
    Set <code>GLASSBILLING_BASE_URL</code> + <code>GLASSBILLING_API_TOKEN</code> to enable live billing data.
</div>
@endif

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Organization</th>
                <th>Billing Email</th>
                <th>Users</th>
                <th>Status</th>
                <th>GlassBilling</th>
                <th>Since</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($organizations as $org)
            <tr>
                <td>
                    <span style="font-weight:500;color:var(--text-h)">{{ $org->name }}</span>
                    <div class="text-sm text-dim">{{ $org->slug }}</div>
                </td>
                <td class="text-sm">{{ $org->billing_email ?? '—' }}</td>
                <td>{{ $org->users_count }}</td>
                <td>
                    <span class="badge badge-{{ $org->status === 'active' ? 'active' : 'inactive' }}">
                        {{ $org->status }}
                    </span>
                </td>
                <td>
                    @if($org->glassbilling_customer_id)
                        <span class="badge badge-active" title="{{ $org->glassbilling_customer_id }}">linked</span>
                    @else
                        <span class="badge badge-unconfigured">not linked</span>
                    @endif
                </td>
                <td class="text-sm text-dim">{{ $org->created_at->format('Y-m-d') }}</td>
                <td>
                    <a href="{{ route('admin.customers.show', $org->id) }}" style="color:var(--accent);text-decoration:none;font-size:.8125rem">View →</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="text-dim" style="text-align:center;padding:2rem">No customers yet.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($organizations->hasPages())
<div style="margin-top:1rem">{{ $organizations->links() }}</div>
@endif

@endsection
