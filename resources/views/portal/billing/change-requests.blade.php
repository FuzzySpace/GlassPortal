@extends('layouts.customer')

@section('title', 'Billing Requests')

@section('content')
@include('portal.billing._nav')

<div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-end">
    <div>
        <h2>Billing Requests</h2>
        <p>Requests you've submitted. Our team reviews every request — submitting one does not change your billing immediately.</p>
    </div>
    <a href="{{ route('portal.billing.change-requests.create') }}" class="badge badge-active" style="text-decoration:none;padding:.45rem .9rem">New request</a>
</div>

<div class="card" style="padding:0">
    <table style="width:100%">
        <thead><tr><th>Type</th><th>Subscription</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
            @forelse($requests as $req)
            <tr>
                <td>{{ $req->typeLabel() }}</td>
                <td class="text-sm text-dim">{{ $req->subscription?->plan?->name ?? ($req->billing_subscription_id ? 'Subscription #'.$req->billing_subscription_id : '—') }}</td>
                <td><span class="badge badge-{{ in_array($req->status, ['approved','completed']) ? 'active' : (in_array($req->status, ['rejected','cancelled']) ? 'inactive' : 'pending') }}">{{ str_replace('_', ' ', $req->status) }}</span></td>
                <td class="text-sm text-dim">{{ $req->requested_at?->format('Y-m-d') ?? $req->created_at?->format('Y-m-d') }}</td>
                <td><a href="{{ route('portal.billing.change-requests.show', $req) }}" class="text-sm">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:2rem">You haven't submitted any billing requests.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($requests->hasPages())<div style="margin-top:1rem">{{ $requests->links() }}</div>@endif
@endsection
