@extends('layouts.staff')

@section('title', 'Create Module Link')
@section('page-title', 'Create Module Link')

@section('content')

<div style="margin-bottom:1.25rem">
    <a href="{{ route('admin.module-links') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Module Links</a>
</div>

<div class="alert alert-info" style="margin-bottom:1.5rem">
    Module links store routing metadata only. <strong>Do not enter API tokens, passwords, or private keys here.</strong>
    <ul style="margin:.5rem 0 0;padding-left:1.25rem;font-size:.875rem">
        <li><strong>signed_launch</strong> — now operational (Phase 8). Requires
            <code>GLASSPORTAL_SIGNED_LAUNCH_SECRET</code> set in the environment.
            Set <em>Launch URL</em> to the module's handoff endpoint. Do not include tokens in the URL.</li>
        <li><strong>shared_session / oauth</strong> — Phase 9+ stubs. Will show "Coming soon" to users.</li>
        <li><strong>standalone / api_token</strong> — uses external_url as a direct launch link. No secrets in the URL.</li>
    </ul>
</div>

@if(empty(config('glasshouse_sso.signing_secret')))
<div class="alert" style="margin-bottom:1rem;background:rgba(210,153,34,.1);border:1px solid var(--warning);padding:.75rem 1rem;border-radius:.5rem">
    <strong style="color:var(--warning)">⚠ Signed launch secret is not configured.</strong>
    <span class="text-sm text-dim"> Links with auth_mode=signed_launch will fail until
    <code>GLASSPORTAL_SIGNED_LAUNCH_SECRET</code> is set.</span>
</div>
@endif

@if($errors->any())
<div class="alert" style="margin-bottom:1rem;background:rgba(248,81,73,.12);border:1px solid var(--danger);padding:.75rem 1rem;border-radius:.5rem">
    @foreach($errors->all() as $error)
    <div class="text-sm" style="color:var(--danger)">{{ $error }}</div>
    @endforeach
</div>
@endif

<div class="card" style="max-width:640px">
    <form method="POST" action="{{ route('admin.module-links.store') }}">
        @csrf

        <div style="display:grid;gap:1.25rem">

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Organization <span style="color:var(--danger)">*</span></label>
                <select name="organization_id" required
                        style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem">
                    <option value="">— select organization —</option>
                    @foreach($organizations as $org)
                    <option value="{{ $org->id }}" @selected(old('organization_id') == $org->id)>{{ $org->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Module <span style="color:var(--danger)">*</span></label>
                <select name="module_key" required
                        style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem">
                    <option value="">— select module —</option>
                    @foreach($moduleKeys as $mk)
                    <option value="{{ $mk }}" @selected(old('module_key') === $mk)>{{ $mk }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Display Name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="display_name" value="{{ old('display_name') }}" required maxlength="255"
                       style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem;box-sizing:border-box">
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Auth Mode <span style="color:var(--danger)">*</span></label>
                <select name="auth_mode" required
                        style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem">
                    @foreach($authModes as $mode)
                    <option value="{{ $mode }}" @selected(old('auth_mode', 'standalone') === $mode)>{{ $mode }}</option>
                    @endforeach
                </select>
                <div class="text-sm text-dim" style="margin-top:.35rem">shared_session / signed_launch / oauth are Phase 8+ stubs — no token exchange occurs.</div>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Status <span style="color:var(--danger)">*</span></label>
                <select name="status" required
                        style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem">
                    @foreach($statuses as $s)
                    <option value="{{ $s }}" @selected(old('status', 'active') === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">External Account ID</label>
                <input type="text" name="external_account_id" value="{{ old('external_account_id') }}" maxlength="255"
                       placeholder="account ID in the external system"
                       style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem;box-sizing:border-box">
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Launch URL</label>
                <input type="url" name="external_url" value="{{ old('external_url') }}" maxlength="2048"
                       placeholder="https://module.example.com"
                       style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem;box-sizing:border-box">
                <div class="text-sm text-dim" style="margin-top:.35rem">Used for standalone auth mode only. Do not paste tokens or credentials in this field.</div>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Metadata (JSON, optional)</label>
                <textarea name="metadata" rows="3" maxlength="8192" placeholder='{"notes":"..."}' style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.8rem;font-family:monospace;box-sizing:border-box;resize:vertical">{{ old('metadata') }}</textarea>
                <div class="text-sm text-dim" style="margin-top:.35rem">Do not store passwords, API tokens, or private keys in metadata.</div>
            </div>

            <div style="display:flex;gap:.75rem;padding-top:.25rem">
                <button type="submit"
                        style="padding:.5rem 1.25rem;background:var(--accent-d);color:#fff;border:none;border-radius:.375rem;font-size:.875rem;font-weight:500;cursor:pointer">
                    Create Link
                </button>
                <a href="{{ route('admin.module-links') }}"
                   style="padding:.5rem .9rem;color:var(--text-dim);font-size:.875rem;text-decoration:none;line-height:1.6">Cancel</a>
            </div>

        </div>
    </form>
</div>

@endsection
