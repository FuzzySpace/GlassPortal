@extends('layouts.staff')

@section('title', 'Provisioning Request')
@section('page-title', 'Provisioning Request')

@php $btn = 'padding:.4rem .85rem;border:none;border-radius:.375rem;font-size:.8rem;font-weight:500;cursor:pointer;color:#fff'; @endphp

@section('content')
<div style="margin-bottom:1rem"><a href="{{ route('admin.provisioning.requests.index') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Provisioning Requests</a></div>

@if(session('success'))<div class="alert alert-info" style="margin-bottom:1rem;color:var(--success)">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-warning" style="margin-bottom:1rem">{{ session('error') }}</div>@endif

<div class="card" style="margin-bottom:1.5rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="section-title" style="margin:0">{{ $request->requested_action }} — {{ $request->service_type ?? 'service' }}</div>
        <span class="badge badge-{{ $request->status === 'completed' ? 'active' : ($request->isTerminal() ? 'inactive' : ($request->status === 'failed' ? 'error' : 'pending')) }}">{{ $request->status }}</span>
    </div>
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:35%">Key</td><td class="text-sm"><code>{{ $request->request_key }}</code></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Customer</td><td class="text-sm">{{ $request->customer?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Organization</td><td class="text-sm">{{ $request->organization?->name ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Entitlement</td><td class="text-sm">
            @if($request->entitlement)<a href="{{ route('admin.billing.entitlements.show', $request->entitlement) }}" style="color:var(--accent);text-decoration:none">{{ $request->entitlement->name }} ({{ $request->entitlement->status }})</a>@else — @endif
        </td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Driver</td><td class="text-sm">{{ $request->driver_key ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Requires approval</td><td class="text-sm">{{ $request->requires_approval ? 'yes' : 'no' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Approved by</td><td class="text-sm">{{ $request->approvedBy?->name ?? '—' }} {{ $request->approved_at?->format('Y-m-d H:i') }}</td></tr>
    </table>

    {{-- Safe payload/result (secret-shaped keys redacted, even for admins) --}}
    <div class="section-title" style="margin-top:1.25rem;margin-bottom:.4rem;font-size:.9rem">Payload <span class="text-dim text-sm">(secrets redacted)</span></div>
    <pre style="background:var(--surface);border:1px solid var(--border);border-radius:.375rem;padding:.6rem;font-size:.75rem;overflow:auto;color:var(--text-dim)">{{ json_encode($request->safePayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @if(!empty($request->result))
    <div class="section-title" style="margin-top:.75rem;margin-bottom:.4rem;font-size:.9rem">Result <span class="text-dim text-sm">(secrets redacted)</span></div>
    <pre style="background:var(--surface);border:1px solid var(--border);border-radius:.375rem;padding:.6rem;font-size:.75rem;overflow:auto;color:var(--text-dim)">{{ json_encode($request->safeResult(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    @endif

    {{-- Controlled actions — only valid transitions are offered --}}
    <div class="section-title" style="margin-top:1.25rem;margin-bottom:.5rem;font-size:.9rem">Actions</div>
    <form method="POST" action="{{ route('admin.provisioning.requests.action', [$request, 'approve']) }}" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
        @csrf
        <input type="text" name="reason" placeholder="reason (optional)" maxlength="255"
               style="background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.35rem .6rem;border-radius:.375rem;font-size:.8rem;min-width:200px">
        @if($request->canApprove())<button type="submit" formaction="{{ route('admin.provisioning.requests.action', [$request, 'approve']) }}" style="{{ $btn }};background:var(--accent-d)">Approve</button>@endif
        @if($request->canReject())<button type="submit" formaction="{{ route('admin.provisioning.requests.action', [$request, 'reject']) }}" style="{{ $btn }};background:var(--warning)">Reject</button>@endif
        @if($request->canQueue())<button type="submit" formaction="{{ route('admin.provisioning.requests.action', [$request, 'queue']) }}" style="{{ $btn }};background:var(--accent-d)">Queue</button>@endif
        @if($request->canStart())<button type="submit" formaction="{{ route('admin.provisioning.requests.action', [$request, 'start']) }}" style="{{ $btn }};background:var(--accent-d)">Start</button>@endif
        @if($request->canComplete())<button type="submit" formaction="{{ route('admin.provisioning.requests.action', [$request, 'complete']) }}" style="{{ $btn }};background:var(--success)">Complete</button>@endif
        @if($request->canFail())<button type="submit" formaction="{{ route('admin.provisioning.requests.action', [$request, 'fail']) }}" style="{{ $btn }};background:var(--danger)">Fail</button>@endif
        @if($request->canCancel())<button type="submit" formaction="{{ route('admin.provisioning.requests.action', [$request, 'cancel']) }}" style="{{ $btn }};background:var(--warning)" onclick="return confirm('Cancel this request?')">Cancel</button>@endif
    </form>
    <div class="text-sm text-dim" style="margin-top:.5rem">Actions only change request + billing entitlement state and are audited. They never provision or touch infrastructure.</div>
</div>

<div class="section-title">Event History</div>
<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Event</th><th>From → To</th><th>Actor</th><th>Message</th><th>When</th></tr></thead>
        <tbody>
            @forelse($request->events as $ev)
            <tr>
                <td class="text-sm" style="color:var(--text-h)">{{ $ev->event_type }}</td>
                <td class="text-sm text-dim">{{ $ev->previous_status ?? '—' }} → {{ $ev->new_status ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $ev->actor_type ? class_basename($ev->actor_type).' #'.$ev->actor_id : 'system' }}</td>
                <td class="text-sm text-dim">{{ $ev->message ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $ev->created_at?->format('Y-m-d H:i') }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-dim" style="text-align:center;padding:1.5rem">No events.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
