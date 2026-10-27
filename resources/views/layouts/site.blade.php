<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <meta name="robots" content="@yield('robots', 'index,follow')">
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --ink:#15171a; --muted:#5f6670; --line:#e3e6ea; --brand:#0f9d6b; --brand-ink:#fff; }
        @media (prefers-color-scheme: dark) {
            :root { --bg:#111315; --card:#1a1d20; --ink:#eceef0; --muted:#9aa1a9; --line:#2b2f34; --brand:#2bd19a; --brand-ink:#08120e; }
        }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif; }
        .wrap { max-width:760px; margin:0 auto; padding:40px 16px 64px; }
        header.top { display:flex; align-items:center; gap:10px; margin-bottom:32px; }
        .logo { width:32px; height:32px; border-radius:8px; background:var(--brand); color:var(--brand-ink); display:grid; place-items:center; font-weight:800; }
        .name { font-weight:700; font-size:18px; }
        h1 { font-size:34px; line-height:1.2; margin:0 0 12px; letter-spacing:-.02em; }
        h2 { font-size:20px; margin:32px 0 8px; }
        p, li { color:var(--muted); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:20px; margin:16px 0; }
        .btn { display:inline-block; background:var(--brand); color:var(--brand-ink); text-decoration:none; font-weight:600; padding:11px 18px; border-radius:10px; }
        a { color:var(--brand); }
        footer { margin-top:48px; font-size:14px; color:var(--muted); }
        code { background:var(--bg); border:1px solid var(--line); border-radius:6px; padding:1px 6px; font-size:14px; }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top"><div class="logo">✓</div><div class="name">{{ config('app.name') }}</div></header>
    @yield('content')
    <footer><a href="{{ route('home') }}">{{ config('app.name') }}</a> · <a href="{{ route('privacy') }}">Privacy</a></footer>
</div>
</body>
</html>
