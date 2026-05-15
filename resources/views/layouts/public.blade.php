<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'GlassPortal') — Glasshouse</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:       #0d1117;
            --surface:  #161b22;
            --border:   #30363d;
            --accent:   #58a6ff;
            --accent-d: #1f6feb;
            --text:     #c9d1d9;
            --text-dim: #8b949e;
            --text-h:   #f0f6fc;
            --success:  #3fb950;
            --warning:  #d29922;
            --danger:   #f85149;
        }
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 1.5rem; }
        nav.public-nav {
            border-bottom: 1px solid var(--border);
            padding: 1rem 0;
        }
        nav.public-nav .inner {
            max-width: 1200px; margin: 0 auto; padding: 0 1.5rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .logo { font-size: 1.25rem; font-weight: 700; color: var(--text-h); letter-spacing: -0.02em; }
        .logo span { color: var(--accent); }
        .nav-links { display: flex; gap: 1rem; align-items: center; }
        .nav-links a { color: var(--text-dim); font-size: 0.875rem; }
        .nav-links a:hover { color: var(--text-h); text-decoration: none; }
        .btn {
            display: inline-flex; align-items: center;
            padding: 0.4rem 1rem; border-radius: 0.375rem;
            font-size: 0.875rem; font-weight: 500; cursor: pointer;
            border: 1px solid var(--border); background: var(--surface);
            color: var(--text); text-decoration: none; transition: border-color 0.15s, color 0.15s;
        }
        .btn:hover { border-color: var(--accent); color: var(--text-h); text-decoration: none; }
        .btn-primary { background: var(--accent-d); border-color: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent); color: #fff; }
        main { padding: 2rem 0; }
    </style>
    @stack('styles')
</head>
<body>
    <nav class="public-nav">
        <div class="inner">
            <a href="{{ url('/') }}" class="logo">Glass<span>Portal</span></a>
            <div class="nav-links">
                @auth
                    @if(auth()->user()->isStaff())
                        <a href="{{ route('admin.dashboard') }}">Staff Portal</a>
                    @else
                        <a href="{{ route('portal.dashboard') }}">My Portal</a>
                    @endif
                @else
                    <a href="{{ route('login') }}" class="btn">Sign in</a>
                @endauth
            </div>
        </div>
    </nav>
    <main>
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
