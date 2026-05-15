@extends('layouts.staff')

@section('title', 'Module Links')
@section('page-title', 'Organization Module Links')

@section('content')

<div style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center">
    <a href="{{ route('admin.modules') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Module Registry</a>
    <a href="{{ route('admin.module-links.create') }}"
       style="display:inline-block;padding:.35rem .85rem;background:var(--accent-d);color:#fff;border-radius:.375rem;font-size:.875rem;font-weight:500;text-decoration:none">
        + Create Link
    </a>
</div>

@if(session('success'))
<div class="alert alert-info" style="margin-bottom:1rem;color:var(--success)">{{ session('success') }}</div>
@endif

<div class="alert alert-info" style="margin-bottom:1rem">
    Module links record which Glasshouse modules are linked to each organization.
    Credentials are never stored here — only account identifiers and routing metadata.
    <code>signed_launch</code> is operational in Phase 8 and requires <code>GLASSPORTAL_SIGNED_LAUNCH_SECRET</code>.
    <code>shared_session</code> and <code>oauth</code> are Phase 9+ stubs.
</div>

@if(empty(config('glasshouse_sso.signing_secret')))
<div class="alert" style="margin-bottom:1rem;background:rgba(210,153,34,.1);border:1px solid var(--warning);padding:.75rem 1rem;border-radius:.5rem">
    <strong style="color:var(--warning)">⚠ Signed launch secret not configured.</strong>
    <span class="text-sm text-dim"> Any active link with <code>auth_mode=signed_launch</code> will fail on launch.
    Set <code>GLASSPORTAL_SIGNED_LAUNCH_SECRET</code> in your environment.</span>
</div>
@endif

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
                <th></th>
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
                    @if($link->isSignedLaunchMode())
                        <span style="color:var(--accent);font-size:.75rem"> ⊛ signed</span>
                    @elseif($link->isSsoMode())
                        <span class="text-dim text-sm"> (Phase 7+)</span>
                    @endif
                </td>
                <td class="text-sm text-dim">{{ $link->external_account_id ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $link->last_seen_at?->diffForHumans() ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $link->updated_at->format('Y-m-d') }}</td>
                <td style="white-space:nowrap">
                    <a href="{{ route('admin.module-links.edit', $link) }}"
                       style="color:var(--accent);font-size:.8rem;text-decoration:none">Edit</a>
                    &nbsp;
                    <form method="POST" action="{{ route('admin.module-links.destroy', $link) }}"
                          style="display:inline"
                          onsubmit="return confirm('Disable this module link?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                style="background:none;border:none;color:var(--danger);font-size:.8rem;cursor:pointer;padding:0">
                            Disable
                        </button>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="text-dim" style="text-align:center;padding:2rem">
                    No module links recorded yet.
                    <a href="{{ route('admin.module-links.create') }}" style="color:var(--accent)">Create the first one.</a>
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
