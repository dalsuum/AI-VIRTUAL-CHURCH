<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $ok ? 'Account Activated' : 'Activation' }} — AI Virtual Church</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif;
            background: #0f1115; color: #e8eaed; padding: 1.5rem;
        }
        .card {
            max-width: 420px; width: 100%; background: #171a21; border: 1px solid #262b35;
            border-radius: 14px; padding: 2.25rem 2rem; text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,.35);
        }
        .icon { font-size: 2.75rem; line-height: 1; margin-bottom: .5rem; }
        h1 { font-size: 1.4rem; margin: .25rem 0 .6rem; letter-spacing: -.02em; }
        p { color: #aab1bd; line-height: 1.55; margin: 0 0 1.4rem; font-size: .95rem; }
        .btn {
            display: inline-block; padding: .7rem 1.4rem; border-radius: 9px;
            background: #6d5efc; color: #fff; text-decoration: none; font-weight: 600; font-size: .95rem;
        }
        .btn:hover { filter: brightness(1.08); }
        .muted { color: #6b7280; font-size: .8rem; margin-top: 1.4rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">{{ $ok ? '✔' : '⚠' }}</div>
        <h1>{{ $heading }}</h1>
        <p>{{ $message }}</p>
        <a class="btn" href="{{ $loginUrl }}">Continue to Login</a>
        <div class="muted">AI Virtual Church</div>
    </div>
</body>
</html>
