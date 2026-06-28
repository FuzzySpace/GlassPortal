@extends('layouts.customer')

@section('title', 'Subscriptions')

@section('content')
@include('portal.billing._nav')

<div class="page-header">
    <h2>Your Subscriptions</h2>
    <p>A read-only view of your subscriptions. To cancel or change a plan, submit a billing request.</p>
</div>

<div class="card" style="padding:0">
    <table style="width:100%">
        <thead><tr><th>Plan</th><th>Status</th><th>Renews / ends</th><th>Auto-renew</th><th></th></tr></thead>
        <tbody>
            @forelse($subscriptions as $sub)
            <tr>
                <td>{{ $sub->plan?->name ?? 'Subscription #'.$sub->id }}</td>
                <td><span class="badge badge-{{ $sub->isLive() ? 'active' : ($sub->status === 'past_due' ? 'pending' : 'inactive') }}">{{ $sub->status }}</span></td>
                <td class="text-sm text-dim">{{ $sub->current_period_end?->format('Y-m-d') ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $sub->cancel_at_period_end ? 'Ends at period' : 'Yes' }}</td>
                <td><a href="{{ route('portal.billing.subscriptions.show', $sub) }}" class="text-sm">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:2rem">You have no subscriptions.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($subscriptions->hasPages())<div style="margin-top:1rem">{{ $subscriptions->links() }}</div>@endif
@endsection
