@extends('layouts.public')

@section('title', 'Products')

@push('styles')
<style>
    .catalog-head { max-width: 1200px; margin: 1rem auto 2rem; padding: 0 1.5rem; }
    .catalog-head h1 { font-size: 2rem; font-weight: 800; letter-spacing: -0.02em; color: var(--text-h); margin-bottom: .5rem; }
    .catalog-head p { color: var(--text-dim); line-height: 1.6; }
    .catalog-grid {
        max-width: 1200px; margin: 0 auto; padding: 0 1.5rem;
        display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem;
    }
    .product-card {
        border: 1px solid var(--border); background: var(--surface); border-radius: .6rem;
        padding: 1.25rem; display: flex; flex-direction: column; transition: border-color .15s;
    }
    .product-card:hover { border-color: var(--accent); }
    .product-card .pc-icon { font-size: 1.5rem; margin-bottom: .5rem; }
    .product-card h2 { font-size: 1.1rem; color: var(--text-h); margin-bottom: .35rem; }
    .product-card .pc-cat {
        display: inline-block; font-size: .7rem; text-transform: uppercase; letter-spacing: .05em;
        color: var(--accent); border: 1px solid rgba(88,166,255,.3); border-radius: 9999px; padding: .1rem .55rem; margin-bottom: .5rem;
    }
    .product-card p { color: var(--text-dim); font-size: .9rem; line-height: 1.55; flex: 1; }
    .product-card .pc-meta { display: flex; align-items: center; justify-content: space-between; margin-top: 1rem; }
    .product-card .pc-price { color: var(--text-h); font-weight: 600; font-size: .9rem; }
    .pc-featured { font-size: .65rem; color: var(--warning); text-transform: uppercase; letter-spacing: .06em; margin-left: .4rem; }
    .catalog-empty { max-width: 1200px; margin: 0 auto; padding: 3rem 1.5rem; text-align: center; color: var(--text-dim); }
</style>
@endpush

@section('content')
<div class="catalog-head">
    <h1>Products &amp; Services</h1>
    <p>Explore the Glasshouse product catalog. Sign in to your portal to manage active services.</p>
</div>

@if($entries->isEmpty())
    <div class="catalog-empty">No products are published yet. Check back soon.</div>
@else
<div class="catalog-grid">
    @foreach($entries as $entry)
    <div class="product-card">
        @if($entry->icon)<div class="pc-icon">{{ $entry->icon }}</div>@endif
        @if($entry->category)<span class="pc-cat">{{ $entry->category }}</span>@endif
        <h2>
            <a href="{{ route('public.products.show', $entry->slug) }}">{{ $entry->title }}</a>
            @if($entry->featured)<span class="pc-featured">★ Featured</span>@endif
        </h2>
        <p>{{ $entry->short_description }}</p>
        <div class="pc-meta">
            <span class="pc-price">{{ $entry->priceLabel() ?? '' }}</span>
            <a href="{{ route('public.products.show', $entry->slug) }}">View details →</a>
        </div>
    </div>
    @endforeach
</div>
@endif
@endsection
