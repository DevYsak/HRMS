{{--
    Every error page uses this one layout. It is deliberately self-contained —
    inline styles, no Vite, no Flux, no database — so a 500 or a maintenance
    page still renders when the thing that broke is the app itself.

    It never shows the exception or a stack trace. A server error shows the
    request id instead (AssignRequestId), which support can match to the log.
--}}
@php
    $requestId = \App\Http\Middleware\AssignRequestId::current();
    $signedIn = rescue(fn () => auth()->check(), false, report: false);
    $home = rescue(fn () => $signedIn ? route('dashboard') : route('login'), url('/'), report: false);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name', 'Pulse') }}</title>
    <style>
        :root { --bg: #fafafa; --card: #ffffff; --border: #f3e8dd; --text: #18181b; --muted: #71717a; --accent: #f97316; --accent-soft: #fff4ea; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1220; --card: #111827; --border: #1f2937; --text: #f4f4f5; --muted: #a1a1aa; --accent-soft: rgba(249, 115, 22, .12); }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: var(--bg); color: var(--text); font: 15px/1.55 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width: 100%; max-width: 440px; background: var(--card); border: 1px solid var(--border); border-radius: 20px; padding: 32px 28px; text-align: center; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .code { display: inline-flex; align-items: center; justify-content: center; min-width: 64px; height: 64px; padding: 0 14px; border-radius: 18px;
                background: var(--accent-soft); color: var(--accent); font-weight: 800; font-size: 22px; letter-spacing: .02em; }
        h1 { margin: 18px 0 6px; font-size: 20px; font-weight: 700; }
        p { margin: 0; color: var(--muted); font-size: 14px; }
        .actions { margin-top: 24px; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .btn { display: inline-flex; align-items: center; justify-content: center; height: 40px; padding: 0 18px; border-radius: 12px; font-weight: 600; font-size: 14px; text-decoration: none; }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-ghost { border: 1px solid var(--border); color: var(--text); }
        .ref { margin-top: 20px; font-size: 12px; color: var(--muted); }
        .ref code { font: 12px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; user-select: all; }
    </style>
</head>
<body>
    <main class="card" role="main">
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>

        <div class="actions">
            @hasSection('primary')
                @yield('primary')
            @else
                <a class="btn btn-primary" href="{{ $home }}">{{ $signedIn ? 'Back to dashboard' : 'Go to sign in' }}</a>
            @endif
            <a class="btn btn-ghost" href="javascript:history.back()">Go back</a>
        </div>

        @if(trim($__env->yieldContent('show_reference')) === 'yes' && $requestId)
            <div class="ref">Reference: <code>{{ $requestId }}</code><br>Quote this if you contact support.</div>
        @endif
    </main>
</body>
</html>
