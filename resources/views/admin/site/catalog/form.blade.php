@extends('layouts.staff')

@section('title', $entry->exists ? 'Edit Catalog Entry' : 'New Catalog Entry')
@section('page-title', $entry->exists ? 'Edit Catalog Entry' : 'New Catalog Entry')

@php
    $field = fn (string $name, $default = null) => old($name, $entry->{$name} ?? $default);
    $inputStyle = 'width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:.45rem .6rem;border-radius:.375rem;font-size:.875rem;box-sizing:border-box';
@endphp

@section('content')

<div style="margin-bottom:1.25rem">
    <a href="{{ route('admin.site.catalog.index') }}" style="color:var(--accent);text-decoration:none;font-size:.875rem">← Catalog</a>
</div>

<div class="alert alert-info" style="margin-bottom:1.5rem">
    Catalog entries are <strong>public</strong>. Only enter information you intend to show on the marketing site.
    <strong>Never enter API tokens, secrets, passwords, customer data, tenant IDs, or infrastructure details — including in metadata.</strong>
</div>

@if($errors->any())
<div class="alert" style="margin-bottom:1rem;background:rgba(248,81,73,.12);border:1px solid var(--danger);padding:.75rem 1rem;border-radius:.5rem">
    @foreach($errors->all() as $error)
    <div class="text-sm" style="color:var(--danger)">{{ $error }}</div>
    @endforeach
</div>
@endif

<div class="card" style="max-width:720px">
    <form method="POST" action="{{ $action }}">
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif

        <div style="display:grid;gap:1.25rem">

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Title <span style="color:var(--danger)">*</span></label>
                <input type="text" name="title" value="{{ $field('title') }}" required maxlength="255" style="{{ $inputStyle }}">
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Slug</label>
                <input type="text" name="slug" value="{{ $field('slug') }}" maxlength="255" placeholder="auto-generated from title" style="{{ $inputStyle }}">
                <div class="text-sm text-dim" style="margin-top:.35rem">Public URL: <code>/products/&lt;slug&gt;</code>. Leave blank to derive from the title.</div>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Short description</label>
                <input type="text" name="short_description" value="{{ $field('short_description') }}" maxlength="500" style="{{ $inputStyle }}">
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Description</label>
                <textarea name="description" rows="5" maxlength="10000" style="{{ $inputStyle }};resize:vertical">{{ $field('description') }}</textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Category</label>
                    <input type="text" name="category" value="{{ $field('category') }}" maxlength="64" style="{{ $inputStyle }}">
                </div>
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Product key</label>
                    <input type="text" name="product_key" value="{{ $field('product_key') }}" maxlength="64" style="{{ $inputStyle }}">
                </div>
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Module key</label>
                    <input type="text" name="module_key" value="{{ $field('module_key') }}" maxlength="64" style="{{ $inputStyle }}">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Starting price (cents)</label>
                    <input type="number" name="starting_price_cents" value="{{ $field('starting_price_cents') }}" min="0" style="{{ $inputStyle }}">
                </div>
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Currency</label>
                    <input type="text" name="currency" value="{{ $field('currency', 'USD') }}" maxlength="3" style="{{ $inputStyle }}">
                </div>
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Billing interval</label>
                    <input type="text" name="billing_interval" value="{{ $field('billing_interval') }}" maxlength="32" placeholder="monthly" style="{{ $inputStyle }}">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 2fr;gap:.75rem">
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">CTA label</label>
                    <input type="text" name="cta_label" value="{{ $field('cta_label') }}" maxlength="64" placeholder="Get started" style="{{ $inputStyle }}">
                </div>
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">CTA URL</label>
                    <input type="url" name="cta_url" value="{{ $field('cta_url') }}" maxlength="2048" placeholder="https://…/signup" style="{{ $inputStyle }}">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Docs URL</label>
                    <input type="url" name="docs_url" value="{{ $field('docs_url') }}" maxlength="2048" style="{{ $inputStyle }}">
                </div>
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Support URL</label>
                    <input type="url" name="support_url" value="{{ $field('support_url') }}" maxlength="2048" style="{{ $inputStyle }}">
                </div>
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Status URL</label>
                    <input type="url" name="status_url" value="{{ $field('status_url') }}" maxlength="2048" style="{{ $inputStyle }}">
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Icon</label>
                    <input type="text" name="icon" value="{{ $field('icon') }}" maxlength="16" placeholder="◆" style="{{ $inputStyle }}">
                </div>
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Sort order</label>
                    <input type="number" name="sort_order" value="{{ $field('sort_order', 0) }}" style="{{ $inputStyle }}">
                </div>
                <div>
                    <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Publish date</label>
                    <input type="datetime-local" name="published_at"
                           value="{{ old('published_at', optional($entry->published_at)->format('Y-m-d\TH:i')) }}" style="{{ $inputStyle }}">
                </div>
            </div>

            <div style="display:flex;gap:1.5rem;align-items:center">
                <label class="text-sm" style="color:var(--text);display:flex;align-items:center;gap:.4rem;cursor:pointer">
                    <input type="checkbox" name="is_public" value="1" @checked(old('is_public', $entry->is_public ?? false))> Public (visible)
                </label>
                <label class="text-sm" style="color:var(--text);display:flex;align-items:center;gap:.4rem;cursor:pointer">
                    <input type="checkbox" name="featured" value="1" @checked(old('featured', $entry->featured ?? false))> Featured
                </label>
            </div>

            <div>
                <label class="text-sm" style="display:block;color:var(--text-dim);margin-bottom:.35rem">Metadata (JSON, optional)</label>
                <textarea name="metadata" rows="3" maxlength="8192" placeholder='{"highlights":["…"]}'
                          style="{{ $inputStyle }};font-family:monospace;font-size:.8rem;resize:vertical">{{ old('metadata', isset($entry->metadata) ? json_encode($entry->metadata) : '') }}</textarea>
                <div class="text-sm text-dim" style="margin-top:.35rem">Admin-only notes. <strong>Never stored publicly and never rendered on the public site.</strong> Do not put secrets here.</div>
            </div>

            <div style="display:flex;gap:.75rem;padding-top:.25rem">
                <button type="submit"
                        style="padding:.5rem 1.25rem;background:var(--accent-d);color:#fff;border:none;border-radius:.375rem;font-size:.875rem;font-weight:500;cursor:pointer">
                    {{ $entry->exists ? 'Save changes' : 'Create entry' }}
                </button>
                <a href="{{ route('admin.site.catalog.index') }}"
                   style="padding:.5rem .9rem;color:var(--text-dim);font-size:.875rem;text-decoration:none;line-height:1.6">Cancel</a>
            </div>

        </div>
    </form>
</div>

@endsection
