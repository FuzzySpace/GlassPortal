@extends('layouts.staff')

@section('title', 'Edit Module Link')
@section('page-title', 'Edit Module Link')

@section('content')

<div style="margin-bottom:1.25rem">
    <a href="{{ route('admin.module-links') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Module Links</a>
</div>

<div class="alert alert-info" style="margin-bottom:1.5rem">
    Module links store routing metadata only. Do not enter API tokens, passwords, or private keys here.
</div>

@if($errors->any())
<div class="alert" style="margin-bottom:1rem;background:rgba(248,81,73,.12);border:1px solid var(--danger);padding:.75rem 1rem;border-radius:.5rem">
    @foreach($errors->all() as $error)
    <div class="text-sm" style="color:var(--danger)">{{ $error }}</div>
    @endforeach
</div>
@endif

<div class="card" style="max-width:640px">
    <form method="POST" action="{{ route('admin.module-links.update', $link) }}">
        @csrf
        @method('PATCH')

        <div style="display:grid;gap:1.25rem">

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Organization <span style="color:var(--danger)">*</span></label>
                <select name="organization_id" required
                        style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem">
                    @foreach($organizations as $org)
                    <option value="{{ $org->id }}" @selected(old('organization_id', $link->organization_id) == $org->id)>{{ $org->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Module <span style="color:var(--danger)">*</span></label>
                <select name="module_key" required
                        style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem">
                    @foreach($moduleKeys as $mk)
                    <option value="{{ $mk }}" @selected(old('module_key', $link->module_key) === $mk)>{{ $mk }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Display Name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="display_name" value="{{ old('display_name', $link->display_name) }}" required maxlength="255"
                       style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem;box-sizing:border-box">
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Auth Mode <span style="color:var(--danger)">*</span></label>
                <select name="auth_mode" required
                        style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem">
                    @foreach($authModes as $mode)
                    <option value="{{ $mode }}" @selected(old('auth_mode', $link->auth_mode) === $mode)>{{ $mode }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Status <span style="color:var(--danger)">*</span></label>
                <select name="status" required
                        style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem">
                    @foreach($statuses as $s)
                    <option value="{{ $s }}" @selected(old('status', $link->status) === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">External Account ID</label>
                <input type="text" name="external_account_id" value="{{ old('external_account_id', $link->external_account_id) }}" maxlength="255"
                       style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem;box-sizing:border-box">
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Launch URL</label>
                <input type="url" name="external_url" value="{{ old('external_url', $link->external_url) }}" maxlength="2048"
                       placeholder="https://module.example.com"
                       style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem;box-sizing:border-box">
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Metadata (JSON, optional)</label>
                <textarea name="metadata" rows="3" maxlength="8192"
                          style="width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.8rem;font-family:monospace;box-sizing:border-box;resize:vertical">{{ old('metadata', $link->metadata ? json_encode($link->metadata, JSON_PRETTY_PRINT) : '') }}</textarea>
            </div>

            <div style="display:flex;gap:.75rem;padding-top:.25rem">
                <button type="submit"
                        style="padding:.5rem 1.25rem;background:var(--accent-d);color:#fff;border:none;border-radius:.375rem;font-size:.875rem;font-weight:500;cursor:pointer">
                    Save Changes
                </button>
                <a href="{{ route('admin.module-links') }}"
                   style="padding:.5rem .9rem;color:var(--text-dim);font-size:.875rem;text-decoration:none;line-height:1.6">Cancel</a>
            </div>

        </div>
    </form>
</div>

@endsection
