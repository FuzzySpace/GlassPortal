@extends('layouts.staff')

@section('title', 'Billing Customers')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr><th>Name</th><th>Email</th><th>Organization</th><th>Stripe</th><th>Status</th><th>Subs</th><th>Invoices</th><th></th></tr>
        </thead>
        <tbody>
            @forelse($customers as $c)
            <tr>
                <td style="color:var(--text-h)">{{ $c->name ?? '—' }}</td>
                <td class="text-sm">{{ $c->email ?? '—' }}</td>
                <td class="text-sm text-dim">
                    @if($c->organization)
                        <a href="{{ route('admin.customers.show', $c->organization_id) }}" style="color:var(--accent);text-decoration:none">{{ $c->organization->name }}</a>
                    @else — @endif
                </td>
                <td>@if($c->isLinkedToStripe())<span class="badge badge-active">linked</span>@else<span class="text-dim text-sm">—</span>@endif</td>
                <td><span class="badge badge-{{ $c->status === 'active' ? 'active' : 'inactive' }}">{{ $c->status }}</span></td>
                <td class="text-sm text-dim">{{ $c->subscriptions_count }}</td>
                <td class="text-sm text-dim">{{ $c->invoices_count }}</td>
                <td><a href="{{ route('admin.billing.customers.show', $c) }}" style="color:var(--accent);font-size:.8rem;text-decoration:none">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-dim" style="text-align:center;padding:2rem">No billing customers yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($customers->hasPages())<div style="margin-top:1rem">{{ $customers->links() }}</div>@endif
@endsection
