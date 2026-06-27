@extends('layouts.staff')

@section('title', 'Billing Events')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div class="alert alert-info" style="margin-bottom:1rem">
    Provider webhook intake log. Each <code>provider_event_id</code> is unique — duplicate events are rejected.
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Event type</th><th>Provider</th><th>Provider event ID</th><th>Status</th><th>Processed</th><th>Received</th><th></th></tr></thead>
        <tbody>
            @forelse($events as $e)
            <tr>
                <td class="text-sm" style="color:var(--text-h)">{{ $e->event_type }}</td>
                <td class="text-sm text-dim">{{ $e->provider }}</td>
                <td class="text-sm text-dim"><code>{{ $e->provider_event_id ?? '—' }}</code></td>
                <td><span class="badge badge-{{ $e->status === 'processed' ? 'active' : ($e->status === 'failed' ? 'error' : 'pending') }}">{{ $e->status }}</span></td>
                <td class="text-sm text-dim">{{ $e->processed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $e->created_at?->format('Y-m-d H:i') }}</td>
                <td><a href="{{ route('admin.billing.events.show', $e) }}" style="color:var(--accent);font-size:.8rem;text-decoration:none">View →</a></td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-dim" style="text-align:center;padding:2rem">No billing events recorded yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if($events->hasPages())<div style="margin-top:1rem">{{ $events->links() }}</div>@endif
@endsection
