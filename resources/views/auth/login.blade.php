<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <style>
        :root {
            --bg: #f3f6fb;
            --surface: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --line: #d7dfeb;
            --brand: #0b5fff;
            --danger: #991b1b;
            --danger-bg: #fee2e2;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "IBM Plex Sans", "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
            color: var(--text);
            background: radial-gradient(circle at 20% 10%, #f9fbff 0%, var(--bg) 45%, #ecf2fa 100%);
            display: grid;
            place-items: center;
            padding: 24px;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 16px;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.08);
            padding: 22px;
        }
        .eyebrow {
            margin: 0;
            color: #1d4ed8;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 700;
        }
        h1 { margin: 8px 0 4px; font-size: 26px; }
        .desc { margin: 0 0 18px; color: var(--muted); font-size: 14px; }
        .error {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #fecaca;
            background: var(--danger-bg);
            color: var(--danger);
            font-size: 13px;
        }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; }
        input[type="email"], input[type="password"] {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            background: #fff;
        }
        input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(11, 95, 255, 0.15);
        }
        .field { margin-bottom: 12px; }
        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 4px 0 16px;
            color: var(--muted);
            font-size: 13px;
        }
        button {
            width: 100%;
            border: 1px solid #0f172a;
            background: #0f172a;
            color: #fff;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        button:hover { background: #1e293b; }
    </style>
</head>
<body>
<main class="card">
    <p class="eyebrow">SaaS Agent Console</p>
    <h1>Admin Login</h1>
    <p class="desc">Masuk sebagai tenant admin atau superadmin untuk mengelola workflow secara terkontrol.</p>

    @if(session('login_error'))
        <div class="error">{{ session('login_error') }}</div>
    @endif

    <form method="POST" action="/login">
        @csrf
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>

        <label class="remember" for="remember">
            <input id="remember" name="remember" type="checkbox" value="1" {{ old('remember') ? 'checked' : '' }}>
            <span>Remember this device</span>
        </label>

        <button type="submit">Login</button>
    </form>
</main>
</body>
</html>
