<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — GlassPortal</title>
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
        .card { max-width: 480px; width: 100%; padding: 3rem 2rem; text-align: center; }
        .badge {
            display: inline-block;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #f59e0b;
            border: 1px solid #78350f;
            border-radius: 9999px;
            padding: 0.25rem 0.75rem;
            margin-bottom: 1.5rem;
        }
        h1 { font-size: 1.75rem; font-weight: 700; margin-bottom: 0.75rem; }
        p { color: #94a3b8; line-height: 1.6; margin-bottom: 2rem; }
        a {
            font-size: 0.875rem;
            color: #60a5fa;
            text-decoration: none;
        }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">Phase {{ $phase }} — Coming Soon</div>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
        <a href="/">← Back to GlassPortal</a>
    </div>
</body>
</html>
