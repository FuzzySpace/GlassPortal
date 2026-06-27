@extends('layouts.staff')

@section('title', 'Site Catalog')
@section('page-title', 'GlassSite — Product Catalog')

@section('content')

<div style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center">
    <a href="{{ route('public.products.index') }}" target="_blank" style="color:var(--accent);text-decoration:none;font-size:.875rem">View public site ↗</a>
    <a href="{{ route('admin.site.catalog.create') }}"
       style="display:inline-block;padding:.35rem .85rem;background:var(--accent-d);color:#fff;border-radius:.375rem;font-size:.875rem;font-weight:500;text-decoration:none">
        + New Entry
    </a>
</div>

@if(session('success'))
<div class="alert alert-info" style="margin-bottom:1rem;color:var(--success)">{{ session('success') }}</div>
@endif

<div class="alert alert-info" style="margin-bottom:1rem">
    GlassSite catalog entries are public marketing cards shown at <code>/products</code>.
    Only entries that are <strong>published</strong> (public + a publish date) appear on the site.
    <strong>Never put secrets, tokens, customer data, or infrastructure details in any field — including metadata.</strong>
</div>

<div class="card" style="padding:0">
    <table>
        <thead>
            <tr>
                <th>Title</th>
                <th>Category</th>
                <th>Price</th>
                <th>Visibility</th>
                <th>Featured</th>
                <th>Sort</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
            <tr>
                <td>
                    <span style="color:var(--text-h)">{{ $entry->icon }} {{ $entry->title }}</span>
                    <div class="text-sm text-dim">{{ $entry->slug }}</div>
                </td>
                <td class="text-sm text-dim">{{ $entry->category ?? '—' }}</td>
                <td class="text-sm text-dim">{{ $entry->priceLabel() ?? '—' }}</td>
                <td>
                    @if($entry->isPublished())
                        <span class="badge badge-active">published</span>
                    @elseif($entry->is_public)
                        <span class="badge badge-pending">scheduled</span>
                    @else
                        <span class="badge badge-inactive">draft</span>
                    @endif
                </td>
                <td>
                    <form method="POST" action="{{ route('admin.site.catalog.feature', $entry) }}" style="display:inline">
                        @csrf
                        <button type="submit" title="Toggle featured"
                                style="background:none;border:none;cursor:pointer;font-size:1rem;padding:0;color:{{ $entry->featured ? 'var(--warning)' : 'var(--text-dim)' }}">
                            {{ $entry->featured ? '★' : '☆' }}
                        </button>
                    </form>
                </td>
                <td class="text-sm text-dim">{{ $entry->sort_order }}</td>
                <td style="white-space:nowrap">
                    <a href="{{ route('admin.site.catalog.edit', $entry) }}" style="color:var(--accent);font-size:.8rem;text-decoration:none">Edit</a>
                    &nbsp;
                    <form method="POST" action="{{ route('admin.site.catalog.publish', $entry) }}" style="display:inline">
                        @csrf
                        <button type="submit" style="background:none;border:none;color:var(--accent);font-size:.8rem;cursor:pointer;padding:0">
                            {{ $entry->is_public ? 'Unpublish' : 'Publish' }}
                        </button>
                    </form>
                    &nbsp;
                    <form method="POST" action="{{ route('admin.site.catalog.destroy', $entry) }}" style="display:inline"
                          onsubmit="return confirm('Delete this catalog entry?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" style="background:none;border:none;color:var(--danger);font-size:.8rem;cursor:pointer;padding:0">Delete</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="text-dim" style="text-align:center;padding:2rem">
                    No catalog entries yet.
                    <a href="{{ route('admin.site.catalog.create') }}" style="color:var(--accent)">Create the first one.</a>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if($entries->hasPages())
<div style="margin-top:1rem">{{ $entries->links() }}</div>
@endif

@endsection
