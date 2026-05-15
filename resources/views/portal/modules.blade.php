@extends('layouts.customer')

@section('title', 'My Modules')

@section('content')
<div class="page-header">
    <h2>My Modules</h2>
    <p>Glasshouse services linked to your account.</p>
</div>

@if(!$org)
<div class="alert alert-warning" style="margin-bottom:1.5rem">
    Your account is not associated with an organization.
    Contact support to get your account set up.
</div>
@endif

@if(session('error'))
<div class="alert" style="margin-bottom:1rem;background:rgba(248,81,73,.12);border:1px solid var(--danger);padding:.75rem 1rem;border-radius:.5rem;color:var(--danger)">
    {{ session('error') }}
</div>
@endif

<div class="alert alert-info" style="margin-bottom:1.5rem">
    <strong>One-login vision:</strong>
    Future phases will enable seamless single sign-on across all linked modules.
    Currently, modules using <em>standalone</em> or <em>local</em> auth require
    separate login credentials. Launch opens the module in a new tab.
</div>

<div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
    @forelse($modules as $key => $module)
    @php
        $linked   = ($module['status'] ?? 'not_linked') !== 'not_linked';
        $active   = $module['status'] === 'active';
        $hasUrl   = !empty($module['launch_url']);
        $setup    = $module['setup_required'] ?? true;
        $warnings = $module['warnings'] ?? [];
        $authMode = $module['auth_mode'] ?? 'standalone';
        $isSso    = in_array($authMode, ['shared_session', 'signed_launch', 'oauth']);
        $linkId   = $module['link_id'] ?? null;
        $icon     = config("glasshouse.launch_modules.{$key}.icon", '⊕');
    @endphp
    <div class="card" style="display:flex;flex-direction:column;gap:.75rem">
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div style="display:flex;align-items:center;gap:.6rem">
                <span style="font-size:1.25rem;color:var(--accent)">{{ $icon }}</span>
                <div>
                    <div style="font-weight:600;color:var(--text-h)">{{ $module['display_name'] }}</div>
                    <div class="text-sm text-dim">{{ $key }}</div>
                </div>
            </div>
            <div>
                @if($active)
                    <span class="badge badge-active">active</span>
                @elseif($linked)
                    <span class="badge badge-{{ $module['status'] }}">{{ $module['status'] }}</span>
                @else
                    <span class="badge badge-unconfigured">not linked</span>
                @endif
            </div>
        </div>

        @if(!empty($module['description']))
        <p class="text-sm text-dim" style="margin:0;line-height:1.5">{{ $module['description'] }}</p>
        @endif

        @if($linked && !empty($module['external_account_id']))
        <div class="text-sm text-dim">Account: <code>{{ $module['external_account_id'] }}</code></div>
        @endif

        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
            <span class="text-dim text-sm">Auth: <code>{{ $authMode }}</code></span>
            @if($isSso)
                <span class="text-dim text-sm">(Phase 8+)</span>
            @endif
        </div>

        @foreach($warnings as $warning)
        <div class="text-sm" style="color:var(--warning)">⚠ {{ $warning }}</div>
        @endforeach

        <div style="margin-top:auto;padding-top:.5rem;border-top:1px solid var(--border)">
            @if(!$linked)
                <span class="text-sm text-dim">Not linked to your account — contact support.</span>
            @elseif($isSso && $linkId)
                {{-- SSO stub — routes through launch controller for audit logging --}}
                <span class="text-sm text-dim" style="color:var(--warning)">Setup required — contact support.</span>
                <div style="margin-top:.5rem">
                    <a href="{{ route('portal.module.launch', $linkId) }}"
                       style="display:inline-block;padding:.35rem .7rem;background:var(--surface);border:1px solid var(--border);color:var(--text-dim);border-radius:.375rem;font-size:.8rem;text-decoration:none">
                        SSO Setup →
                    </a>
                </div>
                <div class="text-sm text-dim" style="margin-top:.35rem">Single sign-on available in a future release (Phase 7+).</div>
            @elseif($hasUrl && $linkId)
                {{-- Audited launch redirect. The raw URL is in data-url for test verification. --}}
                <a href="{{ route('portal.module.launch', $linkId) }}"
                   data-url="{{ $module['launch_url'] }}"
                   style="display:inline-block;padding:.4rem .9rem;background:var(--accent-d);color:#fff;border-radius:.375rem;font-size:.875rem;font-weight:500;text-decoration:none">
                    Launch →
                </a>
            @elseif($setup)
                <span class="text-sm text-dim" style="color:var(--warning)">Setup required — contact support.</span>
            @else
                <span class="text-sm text-dim">No launch URL configured.</span>
            @endif
        </div>
    </div>
    @empty
    <div class="card" style="grid-column:1/-1;text-align:center;padding:2rem;color:var(--text-dim)">
        No modules registered in the system.
    </div>
    @endforelse
</div>
@endsection
