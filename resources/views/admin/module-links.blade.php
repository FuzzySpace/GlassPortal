@extends('layouts.staff')

@section('title', 'Module Links')
@section('page-title', 'Organization Module Links')

@section('content')

<div style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center">
    <a href="{{ route('admin.modules') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Module Registry</a>
</div>

<div class="alert alert-info" style="margin-bottom:1rem">
    Module links record which Glasshouse modules are linked to each organization.
    Credentials are never stored here — only account identifiers and routing metadata.
    SSO modes (shared_session, signed_launch, oauth) are Phase 7+ work.
</div>

{{-- Filters --}}
<form method="GET" style="display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap">
    <select name="module_key" style="background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.35rem .6rem;border-radius:.375rem;font-size:.875rem">
        <option value="">All modules</option>
        @foreach($moduleKeys as $mk)
            <option value="{{ $mk }}" @selected(request('module_key') === $mk)>{{ $mk }}</option>
        @endforeach
    </select>
    <select name="status" style="background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.35rem .6rem;border-radius:.375rem;font-size:.875rem">
        <option value="">All statuses</option>
        @foreach(['active','inactive','pending','error'] as $s)
            <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
        @endforeach
    </select>
    <button type="submit" style="background:var(--accent-d);color:#fff;border:none;padding:.35rem .75rem;border-radius:.375rem;font-size:.875rem;cursor:pointer">Filter</button>
    <a href="{{ route('admin.module-links') }}" style="color:var(--text-dim);font-size:.875rem;line-height:2">Reset</a>
</form>

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Organization</th>
                <th>Module</th>
                <th>Status</th>
                <th>Auth Mode</th>
                <th>External Account</th>
                <th>Last Seen</th>
                <th>Updated</th>
            </tr>
        </thead>
        <tbody>
            @forelse($links as $link)
            <tr>
                <td>
                    <a href="{{ route('admin.customers.show', $link->organization_id) }}" style="color:var(--accent);text-decoration:none;font-weight:500">
                        {{ $link->organization->name ?? 'Org #'.$link->organization_id }}
                    </a>
                </td>
                <td>
                    <span style="color:var(--text-h)">{{ $link->display_name }}</span>
                    <div class="text-sm text-dim">{{ $link->module_key }}</div>
                </td>
                <td><span class="badge badge-{{ $link->status }}">{{ $link->status }}</span></td>
                <td>
                    <code class="text-sm">{{ $link->auth_mode }}</code>
                    @if($link->isSsoMode())
                        <span class="text-dim text-sm"> (Phase 7+)</span>
                    @endif
                </td>
                <td class="text-sm text-dim">{{ $link->external_account_id ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $link->last_seen_at?->diffForHumans() ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $link->updated_at->format('Y-m-d') }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="text-dim" style="text-align:center;padding:2rem">
                    No module links recorded yet.
                    Links are created when organizations are provisioned into modules.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($links->hasPages())
<div style="margin-top:1rem">{{ $links->links() }}</div>
@endif

@endsection
