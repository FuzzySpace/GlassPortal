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
        <a href="/api/health">API Health</a>
        <a href="/up">Uptime</a>
    </div>
    <div class="status-bar">
        <span class="status-dot"></span>
        Laravel {{ app()->version() }} &nbsp;·&nbsp; PHP {{ PHP_MAJOR_VERSION }}.{{ PHP_MINOR_VERSION }} &nbsp;·&nbsp; {{ app()->environment() }}
    </div>
</div>
@endsection
