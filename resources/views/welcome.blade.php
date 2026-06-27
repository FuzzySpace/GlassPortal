@extends('layouts.public')

@section('title', 'GlassPortal')

@push('styles')
<style>
    .hero {
        max-width: 640px; margin: 5rem auto; padding: 0 1.5rem; text-align: center;
    }
    .hero-badge {
        display: inline-block; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.08em;
        text-transform: uppercase; color: #58a6ff;
        border: 1px solid rgba(88,166,255,.3); border-radius: 9999px;
        padding: 0.25rem 0.8rem; margin-bottom: 1.5rem;
    }
    .hero h1 { font-size: 2.5rem; font-weight: 800; letter-spacing: -0.03em; color: #f0f6fc; margin-bottom: 1rem; }
    .hero h1 span { color: #58a6ff; }
    .hero p { color: #8b949e; line-height: 1.7; font-size: 1.05rem; margin-bottom: 2rem; }
    .hero-links { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
    .hero-links a {
        padding: 0.55rem 1.25rem; border-radius: 0.375rem; font-size: 0.9rem; font-weight: 500;
        border: 1px solid #30363d; color: #c9d1d9; background: #161b22; text-decoration: none;
        transition: border-color .15s, color .15s;
    }
    .hero-links a:hover { border-color: #58a6ff; color: #f0f6fc; }
    .hero-links a.primary { background: #1f6feb; border-color: #58a6ff; color: #fff; }
    .hero-links a.primary:hover { background: #58a6ff; }
    .status-bar { margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid #21262d; font-size: 0.75rem; color: #484f58; }
    .status-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #3fb950; margin-right: .4rem; vertical-align: middle; }
    .featured { max-width: 1000px; margin: 0 auto 4rem; padding: 0 1.5rem; }
    .featured h2 { font-size: 1.1rem; color: #f0f6fc; margin-bottom: 1rem; text-align: center; font-weight: 700; }
    .featured-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; }
    .featured-card { border: 1px solid #30363d; background: #161b22; border-radius: .6rem; padding: 1.1rem; }
    .featured-card .fc-icon { font-size: 1.35rem; }
    .featured-card h3 { font-size: 1rem; color: #f0f6fc; margin: .35rem 0; }
    .featured-card p { color: #8b949e; font-size: .85rem; line-height: 1.5; }
    .featured-card a.fc-link { display: inline-block; margin-top: .65rem; font-size: .85rem; color: #58a6ff; }
    .featured-all { text-align: center; margin-top: 1.5rem; }
</style>
@endpush

@section('content')
<div class="hero">
    <div class="hero-badge">Phase 3 — Auth &amp; Module Shell</div>
    <h1>Glass<span>Portal</span></h1>
    <p>
        Unified customer and staff portal for the Glasshouse ecosystem.<br>
        Authentication, module registry, and GlassBilling connector are now live.
    </p>
    <div class="hero-links">
        <a href="{{ route('login') }}" class="primary">Sign in</a>
        <a href="{{ route('public.products.index') }}">Products</a>
        <a href="/api/health">API Health</a>
    </div>
    <div class="status-bar">
        <span class="status-dot"></span>
        Laravel {{ app()->version() }} &nbsp;·&nbsp; PHP {{ PHP_MAJOR_VERSION }}.{{ PHP_MINOR_VERSION }} &nbsp;·&nbsp; {{ app()->environment() }}
    </div>
</div>

@if(!empty($featuredProducts) && $featuredProducts->isNotEmpty())
<div class="featured">
    <h2>Featured Products</h2>
    <div class="featured-grid">
        @foreach($featuredProducts as $product)
        <div class="featured-card">
            <div class="fc-icon">{{ $product->icon }}</div>
            <h3>{{ $product->title }}</h3>
            <p>{{ $product->short_description }}</p>
            <a class="fc-link" href="{{ route('public.products.show', $product->slug) }}">Learn more →</a>
        </div>
        @endforeach
    </div>
    <div class="featured-all"><a href="{{ route('public.products.index') }}">View all products →</a></div>
</div>
@endif
@endsection
