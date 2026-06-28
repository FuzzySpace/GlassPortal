@extends('layouts.staff')

@section('title', 'Billing Change Requests')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div class="alert alert-info" style="margin-bottom:1rem">
    Customer-submitted billing requests. Reviewing/approving a request is a <strong>workflow action only</strong> — it never mutates Stripe, subscriptions, entitlements, or infrastructure.
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Type</th><th>Customer / Org</th><th>Subscription</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
            @forelse($requests as $req)
            <tr>
                <td class="text-sm" style="color:var(--text-h)">{{ $req->typeLabel() }}</td>
                <td class="text-sm text-dim">{{ $req->user?->name ?? '—' }}@if($req->organization) <span class="text-dim">/ {{ $req->organization->name }}</span>@endif</td>
                <td class="text-sm text-dim">{{ $req->subscription?->plan?->name ?? ($req->billing_subscription_id ? '#'.$req->billing_subscription_id : '—') }}</td>
                <td><span class="badge badge-{{ in_array($req->status, ['approved','completed']) ? 'active' : (in_array($req->status, ['rejected','cancelled']) ? 'inactive' : 'pending') }}">{{ str_replace('_', ' ', $req->status) }}</span></td>
                <td class="text-sm text-dim">{{ $req->requested_at?->format('Y-m-d H:i') ?? $req->created_at?->format('Y-m-d H:i') }}</td>
                <td><a href="{{ route('admin.billing.change-requests.show', $req) }}" style="color:var(--accent);font-size:.8rem;text-decoration:none">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-dim" style="text-align:center;padding:2rem">No billing change requests yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($requests->hasPages())<div style="margin-top:1rem">{{ $requests->links() }}</div>@endif
@endsection
