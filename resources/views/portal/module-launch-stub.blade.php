@extends('layouts.customer')

@section('title', 'Module Launch — Coming Soon')

@section('content')
<div class="page-header">
    <h2>{{ $link->display_name }}</h2>
    <p>Module Launch</p>
</div>

<div class="card" style="max-width:540px;margin:0 auto;text-align:center;padding:2.5rem 2rem">
    <div style="font-size:2.5rem;margin-bottom:1rem;color:var(--accent)">⊛</div>

    <h3 style="color:var(--text-h);margin:0 0 .75rem">Single Sign-On Coming Soon</h3>

    <p style="color:var(--text-dim);line-height:1.6;margin:0 0 1.5rem">
        {{ $reason }}
    </p>

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:.5rem;padding:1rem;text-align:left;margin-bottom:1.5rem">
        <div class="text-sm text-dim" style="margin-bottom:.5rem">Link details</div>
        <div style="display:grid;grid-template-columns:auto 1fr;gap:.35rem .75rem;font-size:.875rem">
            <span class="text-dim">Module</span>
            <span style="color:var(--text-h)">{{ $link->module_key }}</span>
            <span class="text-dim">Auth mode</span>
            <span><code>{{ $link->auth_mode }}</code></span>
            <span class="text-dim">Status</span>
            <span><span class="badge badge-{{ $link->status }}">{{ $link->status }}</span></span>
        </div>
    </div>

    <p style="color:var(--text-dim);font-size:.875rem;margin:0 0 1.5rem">
        If you need immediate access to this module, contact your administrator.
        Your launch attempt has been logged.
    </p>

    <a href="{{ route('portal.modules') }}"
       style="display:inline-block;padding:.45rem 1.1rem;background:var(--surface);border:1px solid var(--border);color:var(--text);border-radius:.375rem;font-size:.875rem;text-decoration:none">
        ← Back to Modules
    </a>
</div>
@endsection
