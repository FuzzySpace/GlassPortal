@extends('layouts.staff')

@section('title', 'Billing Subscriptions')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Customer</th><th>Plan</th><th>Status</th><th>Period end</th><th>Stripe</th></tr></thead>
        <tbody>
            @forelse($subscriptions as $s)
            <tr>
                <td>
                    @if($s->customer)
                        <a href="{{ route('admin.billing.customers.show', $s->customer) }}" style="color:var(--accent);text-decoration:none">{{ $s->customer->name ?? 'Customer #'.$s->billing_customer_id }}</a>
                    @else — @endif
                </td>
                <td class="text-sm text-dim">{{ $s->plan?->name ?? '—' }}</td>
                <td><span class="badge badge-{{ $s->isLive() ? 'active' : 'inactive' }}">{{ $s->status }}</span></td>
                <td class="text-sm text-dim">{{ $s->current_period_end?->format('Y-m-d') ?? '—' }}</td>
                <td>@if($s->stripe_subscription_id)<span class="badge badge-active">linked</span>@else<span class="text-dim text-sm">—</span>@endif</td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:2rem">No subscriptions yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($subscriptions->hasPages())<div style="margin-top:1rem">{{ $subscriptions->links() }}</div>@endif
@endsection
