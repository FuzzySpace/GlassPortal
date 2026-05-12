<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GlassPortal — Glasshouse Ecosystem</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: ui-sans-serif, system-ui, sans-serif;
            background: #0f1117;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            max-width: 540px;
            width: 100%;
            padding: 3rem 2rem;
            text-align: center;
        }
        .badge {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #60a5fa;
            border: 1px solid #1e40af;
            border-radius: 9999px;
            padding: 0.25rem 0.75rem;
            margin-bottom: 1.5rem;
        }
        h1 { font-size: 2.25rem; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 0.75rem; }
        h1 span { color: #60a5fa; }
        p { color: #94a3b8; line-height: 1.6; margin-bottom: 2rem; }
        .links { display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; }
        .link {
            font-size: 0.875rem;
            padding: 0.5rem 1.25rem;
            border-radius: 0.375rem;
            text-decoration: none;
            border: 1px solid #334155;
            color: #94a3b8;
            transition: border-color 0.15s, color 0.15s;
        }
        .link:hover { border-color: #60a5fa; color: #e2e8f0; }
        .status {
            margin-top: 2.5rem;
            font-size: 0.75rem;
            color: #475569;
        }
        .dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #22c55e; margin-right: 0.4rem; vertical-align: middle; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">Phase 2 — Laravel Foundation</div>
        <h1>Glass<span>Portal</span></h1>
        <p>
            Unified customer and staff portal for the Glasshouse ecosystem.<br>
            Core infrastructure is live. Module integrations are coming in Phase 3+.
        </p>
        <div class="links">
            <a class="link" href="/portal">Customer Portal</a>
            <a class="link" href="/admin">Staff Portal</a>
            <a class="link" href="/api/health">API Health</a>
            <a class="link" href="/up">Uptime Check</a>
        </div>
        <p class="status">
            <span class="dot"></span>
            Laravel {{ app()->version() }} &nbsp;·&nbsp; PHP {{ PHP_MAJOR_VERSION }}.{{ PHP_MINOR_VERSION }} &nbsp;·&nbsp; {{ app()->environment() }}
        </p>
    </div>
</body>
</html>
