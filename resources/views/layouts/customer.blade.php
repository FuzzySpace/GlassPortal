<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'My Portal') — GlassPortal</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:      #0d1117;
            --surface: #161b22;
            --border:  #30363d;
            --accent:  #58a6ff;
            --accent-d:#1f6feb;
            --text:    #c9d1d9;
            --text-dim:#8b949e;
            --text-h:  #f0f6fc;
            --success: #3fb950;
            --warning: #d29922;
            --danger:  #f85149;
        }
        html, body { height: 100%; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: var(--bg); color: var(--text); }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* Top navigation */
        header {
            background: var(--bg); border-bottom: 1px solid var(--border);
            position: sticky; top: 0; z-index: 10;
        }
        .header-inner {
            max-width: 1100px; margin: 0 auto; padding: 0 1.5rem;
            display: flex; align-items: center; gap: 2rem; height: 54px;
        }
        .logo { font-size: 1.1rem; font-weight: 700; color: var(--text-h); flex-shrink: 0; }
        .logo span { color: var(--accent); }
        nav.top-nav { display: flex; gap: 0.25rem; flex: 1; }
        nav.top-nav a {
            padding: 0.35rem 0.75rem; border-radius: 0.375rem;
            font-size: 0.875rem; color: var(--text-dim);
            transition: color 0.1s, background 0.1s;
        }
        nav.top-nav a:hover { color: var(--text-h); background: rgba(255,255,255,.04); text-decoration: none; }
        nav.top-nav a.active { color: var(--text-h); background: rgba(88,166,255,.1); }
        .header-user { margin-left: auto; font-size: 0.8rem; color: var(--text-dim); display: flex; align-items: center; gap: 0.75rem; }
        .header-user span { color: var(--text); }

        /* Page */
        .page-wrap { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }
        .page-header { margin-bottom: 1.5rem; }
        .page-header h2 { font-size: 1.25rem; font-weight: 600; color: var(--text-h); }
        .page-header p { font-size: 0.875rem; color: var(--text-dim); margin-top: 0.25rem; }

        /* Cards */
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 0.5rem; padding: 1.25rem; }
        .card-title { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-dim); margin-bottom: 0.5rem; }
        .card-value { font-size: 1.5rem; font-weight: 700; color: var(--text-h); }
        .grid { display: grid; gap: 1rem; }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .grid-2 { grid-template-columns: repeat(2, 1fr); }
        @media (max-width: 700px) { .grid-3, .grid-2 { grid-template-columns: 1fr; } }

        /* Alert/info */
        .alert { padding: 0.75rem 1rem; border-radius: 0.375rem; font-size: 0.875rem; }
        .alert-info { background: rgba(88,166,255,.1); border: 1px solid rgba(88,166,255,.3); color: #79c0ff; }
        .alert-warning { background: rgba(210,153,34,.1); border: 1px solid rgba(210,153,34,.3); color: #e3b341; }

        .badge { display: inline-block; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.05em;
            text-transform: uppercase; padding: 2px 8px; border-radius: 9999px; }
        .badge-active   { background: rgba(63,185,80,.15);  color: var(--success); border: 1px solid rgba(63,185,80,.3); }
        .badge-inactive { background: rgba(248,81,73,.15);  color: var(--danger);  border: 1px solid rgba(248,81,73,.3); }
        .badge-pending  { background: rgba(210,153,34,.15); color: var(--warning); border: 1px solid rgba(210,153,34,.3); }

        .section-title { font-size: 0.9rem; font-weight: 600; color: var(--text-h); margin-bottom: 0.75rem; }
        .mt-2 { margin-top: 1rem; } .mt-3 { margin-top: 1.5rem; }
        .text-dim { color: var(--text-dim); } .text-sm { font-size: 0.8rem; }
    </style>
    @stack('styles')
</head>
<body>
    <header>
        <div class="header-inner">
            <a href="{{ url('/') }}" class="logo">Glass<span>Portal</span></a>
            @php $route = request()->route()?->getName() ?? ''; @endphp
            <nav class="top-nav">
                <a href="{{ route('portal.dashboard') }}" class="{{ $route === 'portal.dashboard' ? 'active' : '' }}">Overview</a>
                <a href="{{ route('portal.services') }}"  class="{{ $route === 'portal.services'  ? 'active' : '' }}">My Services</a>
                <a href="{{ route('portal.entitlements') }}" class="{{ $route === 'portal.entitlements' ? 'active' : '' }}">Entitlements</a>
                <a href="{{ route('portal.provisioning') }}" class="{{ $route === 'portal.provisioning' ? 'active' : '' }}">Provisioning</a>
                <a href="{{ route('portal.billing.plans') }}" class="{{ $route === 'portal.billing.plans' ? 'active' : '' }}">Plans</a>
                <a href="{{ route('portal.modules') }}"   class="{{ $route === 'portal.modules'   ? 'active' : '' }}">Modules</a>
                <a href="#" class="">Invoices <small style="color:var(--text-dim);font-size:.7rem">Phase 7</small></a>
                <a href="{{ route('portal.support') }}"   class="{{ $route === 'portal.support'   ? 'active' : '' }}">Support</a>
                <a href="#">Account</a>
            </nav>
            <div class="header-user">
                <span>{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}" style="display:inline">
                    @csrf
                    <button type="submit" style="background:none;border:none;cursor:pointer;color:var(--text-dim);font:inherit;font-size:.8rem">
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </header>
    <div class="page-wrap">
        @if(session('success'))
            <div class="alert alert-info" style="margin-bottom:1rem">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-warning" style="margin-bottom:1rem">{{ session('error') }}</div>
        @endif
        @yield('content')
    </div>
    @stack('scripts')
</body>
</html>
