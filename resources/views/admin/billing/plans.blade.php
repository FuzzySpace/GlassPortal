@extends('layouts.staff')

@section('title', 'Billing Plans')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Name</th><th>Product</th><th>Price</th><th>Stripe price</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($plans as $plan)
            <tr>
                <td style="color:var(--text-h)">{{ $plan->name }}<div class="text-sm text-dim"><code>{{ $plan->plan_key }}</code></div></td>
                <td class="text-sm text-dim">{{ $plan->product?->name ?? '—' }}</td>
                <td class="text-sm" style="font-variant-numeric:tabular-nums">{{ $plan->priceLabel() }}</td>
                <td>@if($plan->stripe_price_id)<span class="badge badge-active">linked</span>@else<span class="text-dim text-sm">—</span>@endif</td>
                <td><span class="badge badge-{{ $plan->status === 'active' ? 'active' : 'inactive' }}">{{ $plan->status }}</span></td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:2rem">No billing plans yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($plans->hasPages())<div style="margin-top:1rem">{{ $plans->links() }}</div>@endif
@endsection
