@extends('layouts.staff')

@section('title', 'Checkout Sessions')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div class="alert alert-info" style="margin-bottom:1rem">
    Stripe Checkout sessions started by customers. A session is marked complete by the
    <code>checkout.session.completed</code> webhook. Provider IDs are shown read-only; no secrets are stored.
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Session</th><th>Customer</th><th>Plan</th><th>Mode</th><th>Status</th><th>Payment</th><th>Started</th><th></th></tr></thead>
        <tbody>
            @forelse($sessions as $s)
            <tr>
                <td class="text-sm text-dim"><code>{{ $s->provider_session_id }}</code></td>
                <td class="text-sm text-dim">{{ $s->customer?->name ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $s->plan?->name ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $s->mode ?? '—' }}</td>
                <td><span class="badge badge-{{ $s->isComplete() ? 'active' : ($s->isExpired() ? 'inactive' : 'pending') }}">{{ $s->status }}</span></td>
                <td class="text-sm text-dim">{{ $s->payment_status ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $s->created_at?->format('Y-m-d H:i') }}</td>
                <td><a href="{{ route('admin.billing.checkout-sessions.show', $s) }}" style="color:var(--accent);font-size:.8rem;text-decoration:none">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-dim" style="text-align:center;padding:2rem">No checkout sessions yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($sessions->hasPages())<div style="margin-top:1rem">{{ $sessions->links() }}</div>@endif
@endsection
