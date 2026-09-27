{{-- Trang lỗi tiếng Việt, tự chứa CSS (không phụ thuộc Vite/đăng nhập để vẫn hiển thị khi app gặp sự cố). --}}
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code') · {{ config('app.name', 'MEnglish') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f6f7fb; --card: #fff; --text: #1b1c20; --muted: #5b5e68; --primary: #2f4bd6; }
        @media (prefers-color-scheme: dark) { :root { --bg: #121317; --card: #1d1f25; --text: #e6e7ec; --muted: #a6a9b3; --primary: #9fb0ff; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px;
               background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .card { width: 100%; max-width: 440px; background: var(--card); border-radius: 16px; padding: 32px 24px; text-align: center;
                box-shadow: 0 4px 24px rgba(0,0,0,.08); }
        .code { font-size: 56px; font-weight: 800; color: var(--primary); line-height: 1; margin: 0 0 12px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { color: var(--muted); margin: 0 0 24px; line-height: 1.5; overflow-wrap: anywhere; }
        .actions { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; }
        a, button { font: inherit; font-weight: 600; padding: 10px 18px; border-radius: 10px; text-decoration: none; cursor: pointer; border: 1px solid var(--primary); }
        .primary { background: var(--primary); color: #fff; }
        .secondary { background: transparent; color: var(--primary); }
    </style>
</head>
<body>
    <main class="card">
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <button type="button" class="secondary" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/') }}')">Quay lại</button>
            <a class="primary" href="{{ url('/') }}">Về trang chủ</a>
        </div>
    </main>
</body>
</html>
