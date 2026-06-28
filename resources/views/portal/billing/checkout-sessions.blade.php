@extends('layouts.customer')

@section('title', 'Checkout History')

@section('content')
@include('portal.billing._nav')

<div class="page-header">
    <h2>Checkout History</h2>
    <p>Stripe Checkout sessions you've started. Card details are entered on Stripe — GlassPortal never sees them.</p>
</div>

<div class="card" style="padding:0">
    <table style="width:100%">
        <thead><tr><th>Plan</th><th>Status</th><th>Payment</th><th>Amount</th><th>Started</th><th></th></tr></thead>
        <tbody>
            @forelse($sessions as $session)
            <tr>
                <td class="text-sm">{{ $session->plan?->name ?? '—' }}</td>
                <td><span class="badge badge-{{ $session->isComplete() ? 'active' : ($session->isExpired() ? 'inactive' : 'pending') }}">{{ $session->status }}</span></td>
                <td class="text-sm text-dim">{{ $session->payment_status ?? '—' }}</td>
                <td class="text-sm">@if($session->amount_total)${{ number_format($session->amount_total / 100, 2) }} {{ $session->currency }}@else — @endif</td>
                <td class="text-sm text-dim">{{ $session->created_at?->format('Y-m-d') }}</td>
                <td><a href="{{ route('portal.billing.checkout-sessions.show', $session) }}" class="text-sm">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-dim" style="text-align:center;padding:2rem">No checkout sessions yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($sessions->hasPages())<div style="margin-top:1rem">{{ $sessions->links() }}</div>@endif
@endsection
