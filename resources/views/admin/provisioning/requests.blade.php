@extends('layouts.staff')

@section('title', 'Provisioning Requests')
@section('page-title', 'Provisioning Requests')

@section('content')

@if(session('success'))<div class="alert alert-info" style="margin-bottom:1rem;color:var(--success)">{{ session('success') }}</div>@endif

<div class="alert alert-info" style="margin-bottom:1rem">
    Approval-gated requests to fulfill billing entitlements. Billing determines entitlement; this engine records the
    <strong>request</strong> and its lifecycle. <strong>It never provisions or touches infrastructure</strong> —
    future drivers ({{ implode(', ', $drivers) }}) will consume approved/queued requests in a later phase.
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Request</th><th>Customer</th><th>Action</th><th>Driver</th><th>Status</th><th>Created</th><th></th></tr></thead>
        <tbody>
            @forelse($requests as $r)
            <tr>
                <td><span style="color:var(--text-h)">{{ $r->service_type ?? 'service' }}</span><div class="text-sm text-dim"><code>{{ $r->request_key }}</code></div></td>
                <td class="text-sm text-dim">{{ $r->customer?->name ?? ($r->organization?->name ?? '—') }}</td>
                <td class="text-sm"><code>{{ $r->requested_action }}</code></td>
                <td class="text-sm text-dim">{{ $r->driver_key ?? '—' }}</td>
                <td><span class="badge badge-{{ $r->status === 'completed' ? 'active' : ($r->isTerminal() ? 'inactive' : ($r->status === 'failed' ? 'error' : 'pending')) }}">{{ $r->status }}</span></td>
                <td class="text-sm text-dim">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                <td><a href="{{ route('admin.provisioning.requests.show', $r) }}" style="color:var(--accent);font-size:.8rem;text-decoration:none">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-dim" style="text-align:center;padding:2rem">No provisioning requests yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($requests->hasPages())<div style="margin-top:1rem">{{ $requests->links() }}</div>@endif
@endsection
