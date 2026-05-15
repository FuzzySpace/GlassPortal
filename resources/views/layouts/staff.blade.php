<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Staff Portal') — GlassPortal</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg:        #0d1117;
            --sidebar:   #0d1117;
            --surface:   #161b22;
            --surface2:  #21262d;
            --border:    #30363d;
            --accent:    #58a6ff;
            --accent-d:  #1f6feb;
            --text:      #c9d1d9;
            --text-dim:  #8b949e;
            --text-h:    #f0f6fc;
            --success:   #3fb950;
            --warning:   #d29922;
            --danger:    #f85149;
            --sidebar-w: 220px;
        }
        html, body { height: 100%; }
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: var(--bg); color: var(--text); display: flex; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* Sidebar */
        .sidebar {
            width: var(--sidebar-w); flex-shrink: 0;
            background: var(--sidebar);
            border-right: 1px solid var(--border);
            display: flex; flex-direction: column;
            position: fixed; top: 0; bottom: 0; left: 0;
            overflow-y: auto;
        }
        .sidebar-logo {
            padding: 1.25rem 1.25rem 0.75rem;
            font-size: 1.1rem; font-weight: 700; color: var(--text-h);
            border-bottom: 1px solid var(--border);
        }
        .sidebar-logo span { color: var(--accent); }
        .sidebar-badge {
            display: inline-block; font-size: 0.6rem; font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--text-dim); background: var(--surface2);
            border-radius: 4px; padding: 1px 5px; margin-left: 6px; vertical-align: middle;
        }
        nav.sidebar-nav { padding: 0.75rem 0; flex: 1; }
        nav.sidebar-nav .nav-section {
            font-size: 0.65rem; font-weight: 700; letter-spacing: 0.08em;
            text-transform: uppercase; color: var(--text-dim);
            padding: 0.75rem 1.25rem 0.25rem;
        }
        nav.sidebar-nav a {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.45rem 1.25rem; font-size: 0.875rem; color: var(--text-dim);
            border-left: 2px solid transparent; transition: color 0.1s, border-color 0.1s;
        }
        nav.sidebar-nav a:hover, nav.sidebar-nav a.active {
            color: var(--text-h); border-left-color: var(--accent);
            background: rgba(88, 166, 255, 0.06); text-decoration: none;
        }
        nav.sidebar-nav a .icon { width: 14px; text-align: center; font-size: 0.8rem; }
        .sidebar-user {
            padding: 0.85rem 1.25rem;
            border-top: 1px solid var(--border);
            font-size: 0.8rem; color: var(--text-dim);
        }
        .sidebar-user .user-name { color: var(--text); font-weight: 500; display: block; margin-bottom: 0.15rem; }
        .sidebar-user .user-role {
            display: inline-block; font-size: 0.65rem; font-weight: 700; letter-spacing: 0.06em;
            text-transform: uppercase; padding: 1px 6px; border-radius: 3px;
            background: var(--surface2); color: var(--text-dim); margin-bottom: 0.4rem;
        }
        .sidebar-logout { color: var(--text-dim); font-size: 0.8rem; }
        .sidebar-logout:hover { color: var(--danger); text-decoration: none; }

        /* Main content */
        .main-wrap { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
        .topbar {
            height: 52px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; padding: 0 1.5rem;
            background: var(--bg); position: sticky; top: 0; z-index: 10;
        }
        .topbar h1 { font-size: 0.95rem; font-weight: 600; color: var(--text-h); }
        .page-content { padding: 1.5rem; flex: 1; }

        /* Cards */
        .card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 0.5rem; padding: 1.25rem;
        }
        .card-title { font-size: 0.8rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.06em; color: var(--text-dim); margin-bottom: 0.5rem; }
        .card-value { font-size: 1.75rem; font-weight: 700; color: var(--text-h); }
        .card-sub { font-size: 0.8rem; color: var(--text-dim); margin-top: 0.25rem; }
        .grid { display: grid; gap: 1rem; }
        .grid-4 { grid-template-columns: repeat(4, 1fr); }
        .grid-3 { grid-template-columns: repeat(3, 1fr); }
        .grid-2 { grid-template-columns: repeat(2, 1fr); }
        @media (max-width: 900px) { .grid-4 { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 600px) { .grid-4, .grid-3, .grid-2 { grid-template-columns: 1fr; } }

        /* Status badges */
        .badge { display: inline-block; font-size: 0.7rem; font-weight: 600;
            letter-spacing: 0.05em; text-transform: uppercase;
            padding: 2px 8px; border-radius: 9999px; }
        .badge-online  { background: rgba(63,185,80,.15);  color: var(--success); border: 1px solid rgba(63,185,80,.3); }
        .badge-offline { background: rgba(248,81,73,.15);  color: var(--danger);  border: 1px solid rgba(248,81,73,.3); }
        .badge-stub    { background: rgba(210,153,34,.15); color: var(--warning); border: 1px solid rgba(210,153,34,.3); }
        .badge-unconfigured { background: var(--surface2); color: var(--text-dim); border: 1px solid var(--border); }
        .badge-disabled     { background: var(--surface2); color: var(--text-dim); border: 1px solid var(--border); }

        /* Tables */
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th { text-align: left; padding: 0.6rem 0.75rem; color: var(--text-dim);
            font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.05em; border-bottom: 1px solid var(--border); }
        td { padding: 0.65rem 0.75rem; border-bottom: 1px solid rgba(48,54,61,0.5); color: var(--text); }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,.02); }

        /* Alerts */
        .alert { padding: 0.75rem 1rem; border-radius: 0.375rem; font-size: 0.875rem; margin-bottom: 1rem; }
        .alert-warning { background: rgba(210,153,34,.1); border: 1px solid rgba(210,153,34,.3); color: #e3b341; }
        .alert-info    { background: rgba(88,166,255,.1);  border: 1px solid rgba(88,166,255,.3); color: #79c0ff; }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.4rem 0.9rem; border-radius: 0.375rem;
            font-size: 0.8rem; font-weight: 500; cursor: pointer;
            border: 1px solid var(--border); background: var(--surface2);
            color: var(--text); text-decoration: none; transition: border-color 0.15s;
        }
        .btn:hover { border-color: var(--accent); color: var(--text-h); text-decoration: none; }

        .section-title { font-size: 1rem; font-weight: 600; color: var(--text-h); margin-bottom: 1rem; }
        .mt-1 { margin-top: 0.5rem; } .mt-2 { margin-top: 1rem; } .mt-3 { margin-top: 1.5rem; }
        .text-dim { color: var(--text-dim); } .text-sm { font-size: 0.8rem; }
    </style>
    @stack('styles')
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-logo">
            Glass<span>Portal</span><span class="sidebar-badge">Staff</span>
        </div>
        @php $route = request()->route()?->getName() ?? ''; @endphp
        <nav class="sidebar-nav">
            <div class="nav-section">Operations</div>
            <a href="{{ route('admin.dashboard') }}" class="{{ str_starts_with($route, 'admin.dashboard') ? 'active' : '' }}">
                <span class="icon">▦</span> Dashboard
            </a>
            <a href="{{ route('admin.customers') }}" class="{{ str_starts_with($route, 'admin.customers') ? 'active' : '' }}">
                <span class="icon">◉</span> Customers
            </a>
            <a href="{{ route('admin.services') }}" class="{{ str_starts_with($route, 'admin.services') ? 'active' : '' }}">
                <span class="icon">⊞</span> Services
            </a>
            <a href="{{ route('admin.provisioning') }}" class="{{ str_starts_with($route, 'admin.provisioning') ? 'active' : '' }}">
                <span class="icon">⊕</span> Provisioning
            </a>
            <a href="{{ route('admin.billing-approvals') }}" class="{{ str_starts_with($route, 'admin.billing-approvals') ? 'active' : '' }}">
                <span class="icon">◈</span> Invoice Approvals
            </a>

            <div class="nav-section">System</div>
            <a href="{{ route('admin.modules') }}" class="{{ str_starts_with($route, 'admin.modules') && !str_starts_with($route, 'admin.module-links') ? 'active' : '' }}">
                <span class="icon">⊛</span> Modules
            </a>
            <a href="{{ route('admin.module-links') }}" class="{{ str_starts_with($route, 'admin.module-links') ? 'active' : '' }}">
                <span class="icon">⊞</span> Module Links
            </a>
            <a href="#" class="{{ $route === 'admin.billing' ? 'active' : '' }}">
                <span class="icon">◈</span> Billing <span class="sidebar-badge">Phase 7</span>
            </a>
            <a href="#" class="{{ $route === 'admin.support' ? 'active' : '' }}">
                <span class="icon">◎</span> Support <span class="sidebar-badge">Phase 4</span>
            </a>
            <a href="#" class="{{ $route === 'admin.settings' ? 'active' : '' }}">
                <span class="icon">⊙</span> Settings <span class="sidebar-badge">Phase 4</span>
            </a>
        </nav>
        <div class="sidebar-user">
            <span class="user-name">{{ auth()->user()->name }}</span>
            <span class="user-role">{{ auth()->user()->role?->label() }}</span>
            <form method="POST" action="{{ route('logout') }}" style="margin-top:0.4rem">
                @csrf
                <button type="submit" class="sidebar-logout" style="background:none;border:none;cursor:pointer;padding:0;font:inherit">
                    Sign out
                </button>
            </form>
        </div>
    </aside>

    <div class="main-wrap">
        <div class="topbar">
            <h1>@yield('page-title', 'Dashboard')</h1>
        </div>
        <div class="page-content">
            @if(session('success'))
                <div class="alert alert-info" style="margin-bottom:1rem">{{ session('success') }}</div>
            @endif
            @yield('content')
        </div>
    </div>
    @stack('scripts')
</body>
</html>
