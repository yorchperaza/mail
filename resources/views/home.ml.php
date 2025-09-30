<?php
// This template is plain PHP + HTML, no Blade syntax.
// Variables available: $title (from controller)
$title = isset($title) ? (string) $title : 'MonkeysMail';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta
            name="viewport"
            content="width=device-width, initial-scale=1, viewport-fit=cover"
    />
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="robots" content="noindex, nofollow" />

    <style>
        :root {
            --ink: #0f172a; /* slate-900 */
            --muted: #475569; /* slate-600 */
            --brand: #2563eb; /* blue-600 */
            --brand2: #06b6d4; /* cyan-500 */
        }
        html, body { height: 100%; }
        body {
            margin: 0;
            color: var(--ink);
            background:
                    radial-gradient(900px 600px at 10% -10%, rgba(99,102,241,.12), transparent 60%),
                    radial-gradient(700px 500px at 110% 10%, rgba(34,211,238,.10), transparent 60%),
                    linear-gradient(180deg, #f8fafc, #ffffff 30%, #f0f9ff 100%);
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, 'Helvetica Neue', Arial, 'Apple Color Emoji', 'Segoe UI Emoji';
            overflow-x: hidden;
        }
        .grid { position: fixed; inset: 0; pointer-events: none; opacity: .04;
            background-image:
                    linear-gradient(90deg, rgba(15,23,42,.7) 1px, transparent 1px),
                    linear-gradient(0deg, rgba(15,23,42,.7) 1px, transparent 1px);
            background-size: 48px 48px;
        }
        .blob { position: fixed; filter: blur(38px); opacity: .35; mix-blend-mode: multiply; animation: float 9s ease-in-out infinite; }
        .blob.a { top: -120px; left: -120px; width: 360px; height: 360px; background: #93c5fd; }
        .blob.b { bottom: -140px; right: -120px; width: 420px; height: 420px; background: #67e8f9; animation-delay: 2.5s; }
        .blob.c { top: 30%; left: 60%; width: 300px; height: 300px; background: #a5b4fc; animation-delay: 1.2s; }
        @keyframes float { 0%,100%{transform:translate(0,0) scale(1)} 33%{transform:translate(24px,-28px) scale(1.06)} 66%{transform:translate(-16px,20px) scale(.96)} }

        .wrap { min-height: 100%; display: grid; place-items: center; padding: 24px; }
        .card {
            width: min(720px, 92vw);
            background: rgba(255,255,255,.82);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(37,99,235,.15);
            box-shadow: 0 10px 25px rgba(2,6,23,.08), 0 2px 8px rgba(2,6,23,.06);
            border-radius: 24px;
            padding: 28px;
        }
        .badge {
            display: inline-flex; align-items: center; gap: 8px;
            font-weight: 700; font-size: 12px; color: #0b4fd6;
            background: linear-gradient(90deg, #dbedff, #ccfbff);
            padding: 8px 12px; border-radius: 999px;
            border: 1px solid rgba(37,99,235,.25);
        }
        h1 {
            margin: 10px 0 6px;
            font-size: clamp(28px, 4vw, 42px);
            line-height: 1.05;
            font-weight: 900;
            letter-spacing: -0.015em;
            background: linear-gradient(90deg, #0f172a, #0b4fd6 60%, #06b6d4);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        p.sub { margin: 0 0 18px; color: var(--muted); font-size: 15px; }
        .cta {
            display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            font-weight: 800; font-size: 15px; letter-spacing: .2px; text-decoration: none;
            color: white; background: linear-gradient(90deg, var(--brand), var(--brand2));
            border: 0; border-radius: 14px; padding: 14px 18px; cursor: pointer;
            box-shadow: 0 10px 20px rgba(37,99,235,.25), inset 0 0 0 1px rgba(255,255,255,.2);
            transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
        }
        .cta:hover { transform: translateY(-1px); filter: brightness(1.03); }
        .cta:active { transform: translateY(0); box-shadow: 0 6px 14px rgba(37,99,235,.25); }
        .note { margin-top: 10px; font-size: 12px; color: var(--muted); }
        .sr { position: absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
        .logo {
            display: block;
            margin: 0 auto 16px;
            max-width: 220px;
        }
    </style>
</head>
<body>
<div class="grid" aria-hidden="true"></div>
<div class="blob a" aria-hidden="true"></div>
<div class="blob b" aria-hidden="true"></div>
<div class="blob c" aria-hidden="true"></div>

<main class="wrap">
    <div class="card">
        <img src="https://monkeysmail.com/logo.svg"
             alt="MonkeysMail"
             class="logo" />
        <span class="badge" aria-hidden="true">Heads up</span>
        <h1>Oops… wrong jungle 🌴</h1>
        <p class="sub">
            You’ve found a secret subdomain. There’s nothing to see here—just a couple of mischievous monkeys testing bananas. 🍌
            Let’s get you to the main site where the real magic happens.
        </p>

        <a class="cta" href="https://monkeysmail.com" rel="nofollow noopener">
            Take me to Monkeysmail.com <span aria-hidden="true">➡️</span>
            <span class="sr">Opens monkeysmail.com</span>
        </a>

        <p class="note" id="redir-note">
            Auto-redirecting in <span id="count">6</span>s… (press Esc to cancel)
        </p>
    </div>
</main>

<script>
    (function () {
        var TARGET = 'https://monkeysmail.com';
        var countEl = document.getElementById('count');
        var noteEl  = document.getElementById('redir-note');
        var secs = 15, canceled = false;

        function tick () {
            secs -= 1;
            if (countEl) countEl.textContent = String(secs);
            if (secs <= 0) window.location.href = TARGET;
        }

        var iv = setInterval(function () {
            if (canceled) { clearInterval(iv); return; }
            tick();
        }, 1000);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (!canceled && noteEl) noteEl.textContent = 'Auto-redirect canceled. Use the button above.';
                canceled = true;
            }
        });
    })();
</script>
</body>
</html>
