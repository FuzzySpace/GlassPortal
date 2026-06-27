@extends('layouts.public')

@section('title', $entry->title)

@push('styles')
<style>
    .product-detail { max-width: 760px; margin: 1rem auto 4rem; padding: 0 1.5rem; }
    .product-detail .back { font-size: .85rem; color: var(--text-dim); display: inline-block; margin-bottom: 1.5rem; }
    .product-detail .pd-icon { font-size: 2.5rem; margin-bottom: .5rem; }
    .product-detail .pd-cat {
        display: inline-block; font-size: .7rem; text-transform: uppercase; letter-spacing: .05em;
        color: var(--accent); border: 1px solid rgba(88,166,255,.3); border-radius: 9999px; padding: .15rem .6rem; margin-bottom: .75rem;
    }
    .product-detail h1 { font-size: 2rem; font-weight: 800; letter-spacing: -0.02em; color: var(--text-h); margin-bottom: .5rem; }
    .product-detail .lead { color: var(--text); font-size: 1.1rem; line-height: 1.6; margin-bottom: 1.5rem; }
    .product-detail .price { font-size: 1.25rem; font-weight: 700; color: var(--text-h); margin-bottom: 1.5rem; }
    .product-detail .body { color: var(--text-dim); line-height: 1.75; white-space: pre-line; margin-bottom: 2rem; }
    .product-detail .cta-row { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; margin-bottom: 2rem; }
    .product-detail .links { border-top: 1px solid var(--border); padding-top: 1.25rem; display: flex; gap: 1.25rem; flex-wrap: wrap; font-size: .9rem; }
    .product-detail .links a { color: var(--text-dim); }
    .product-detail .links a:hover { color: var(--text-h); }
</style>
@endpush

@section('content')
<div class="product-detail">
    <a href="{{ route('public.products.index') }}" class="back">← All products</a>

    @if($entry->icon)<div class="pd-icon">{{ $entry->icon }}</div>@endif
    @if($entry->category)<div><span class="pd-cat">{{ $entry->category }}</span></div>@endif

    <h1>{{ $entry->title }}</h1>

    @if($entry->short_description)
        <p class="lead">{{ $entry->short_description }}</p>
    @endif

    @if($entry->priceLabel())
        <div class="price">{{ $entry->priceLabel() }}</div>
    @endif

    @if($entry->description)
        <div class="body">{{ $entry->description }}</div>
    @endif

    @if($entry->cta_url)
    <div class="cta-row">
        <a href="{{ $entry->cta_url }}" class="btn btn-primary">{{ $entry->cta_label ?: 'Get started' }}</a>
    </div>
    @endif

    @if($entry->docs_url || $entry->support_url || $entry->status_url)
    <div class="links">
        @if($entry->docs_url)<a href="{{ $entry->docs_url }}">Documentation →</a>@endif
        @if($entry->support_url)<a href="{{ $entry->support_url }}">Support →</a>@endif
        @if($entry->status_url)<a href="{{ $entry->status_url }}">Status →</a>@endif
    </div>
    @endif
</div>
@endsection
