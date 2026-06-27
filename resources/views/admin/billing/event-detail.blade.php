@extends('layouts.staff')

@section('title', 'Billing Event')
@section('page-title', 'GlassBilling')

@section('content')
@include('admin.billing._nav')

<div style="margin-bottom:1rem"><a href="{{ route('admin.billing.events') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Events</a></div>

<div class="card" style="margin-bottom:1.5rem">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <div class="section-title" style="margin:0">{{ $event->event_type }}</div>
        <span class="badge badge-{{ $event->status === 'processed' ? 'active' : ($event->status === 'failed' ? 'error' : 'pending') }}">{{ $event->status }}</span>
    </div>
    <table style="width:100%">
        <tr><td class="text-dim text-sm" style="padding:.3rem 0;width:35%">Provider</td><td class="text-sm">{{ $event->provider }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Provider event ID</td><td class="text-sm"><code>{{ $event->provider_event_id ?? '—' }}</code></td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Processed</td><td class="text-sm">{{ $event->processed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Failure reason</td><td class="text-sm">{{ $event->error_message ?? '—' }}</td></tr>
        <tr><td class="text-dim text-sm" style="padding:.3rem 0">Received</td><td class="text-sm">{{ $event->created_at?->format('Y-m-d H:i') }}</td></tr>
    </table>

    <div class="section-title" style="margin-top:1.25rem;margin-bottom:.4rem;font-size:.9rem">Payload <span class="text-dim text-sm">(secrets redacted)</span></div>
    <pre style="background:var(--surface);border:1px solid var(--border);border-radius:.375rem;padding:.6rem;font-size:.75rem;overflow:auto;color:var(--text-dim)">{{ json_encode($event->safePayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
@endsection
