<?php
/**
 * Custom 404 — "LOST IN SPACE" error page.
 * Standalone public page (like public/jobs.php) — no login required so that
 * any visitor can reach it when a page is not found.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security_headers.php';

http_response_code(404);
$pageTitle = '404 — Page Not Found';

// Destination for the single action button.
$dashboardUrl = BASE_URL . '/public/jobs.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
    <meta name="robots" content="noindex">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: #43109F;
            --brand-dark: #36088C;
            --brand-deep: #2A0A6B;
            --ink: #12102E;
            --muted: #6B7280;
            --faint: #9CA3AF;
            --line: #E7E5EE;
            --lavender: #EDE4FA;
            --lavender-deep: #E5D5FA;
            --lavender-soft: #F4EEFC;
            --violet: #7C3AED;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; background: #fff; }
        html, body { overflow-x: hidden; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--ink);
            background: transparent;
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            -webkit-font-smoothing: antialiased;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(42% 34% at 12% 8%, rgba(229,213,250,.5), rgba(229,213,250,0) 68%),
                radial-gradient(34% 28% at 86% 12%, rgba(237,228,250,.5), rgba(237,228,250,0) 70%),
                radial-gradient(38% 34% at 82% 90%, rgba(124,58,237,.08), rgba(124,58,237,0) 70%),
                linear-gradient(180deg, #FBFAFE 0%, #F7F4FE 60%, #F0EAFB 100%);
        }
        .sr-only {
            position: absolute; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0,0,0,0); white-space: nowrap; border: 0;
        }
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding-left: clamp(20px, 5vw, 48px);
            padding-right: clamp(20px, 5vw, 48px);
        }

        /* ===== Main 404 section ===== */
        .error-main {
            position: relative;
            flex: 1 0 auto;
            text-align: center;
            padding: clamp(34px, 6vw, 70px) 0 20px;
            overflow: hidden;
        }
        .error-kicker {
            margin: 0;
            font-size: clamp(16px, 1.8vw, 20px);
            font-weight: 600;
            color: var(--muted);
        }
        .error-kicker b { color: var(--brand); font-weight: 800; }

        .big-404 {
            position: relative;
            display: inline-block;
            margin: clamp(10px, 2vw, 22px) 0 6px;
            font-size: clamp(120px, 24vw, 260px);
            line-height: 1;
            font-weight: 900;
            letter-spacing: -0.02em;
            color: var(--brand);
            background: linear-gradient(160deg, #7C3AED 0%, #43109F 55%, #2A0A6B 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 18px 30px rgba(67,16,159,.28));
            user-select: none;
            z-index: 2;
        }
        .big-404 .zero {
            position: relative;
            display: inline-block;
            -webkit-text-fill-color: transparent;
        }
        .face-in-zero {
            position: absolute;
            left: 50%;
            top: 46%;
            width: 34%;
            height: 34%;
            transform: translate(-50%, -50%);
            -webkit-text-fill-color: var(--brand-deep);
            pointer-events: none;
        }
        .face-in-zero svg { width: 100%; height: 100%; display: block; }

        /* decorative sparkles around 404 */
        .sparkle { position: absolute; color: var(--brand); opacity: .85; pointer-events: none; z-index: 3; }
        .sparkle-1 { top: -6%; left: -8%; width: 22px; height: 22px; animation: twinkle 3.4s ease-in-out infinite; }
        .sparkle-2 { top: 18%; right: -4%; width: 16px; height: 16px; animation: twinkle 4.2s ease-in-out infinite 1s; }
        .sparkle-3 { bottom: 6%; left: -5%; width: 13px; height: 13px; animation: twinkle 2.8s ease-in-out infinite .4s; }
        .sparkle-4 { bottom: -2%; right: 2%; width: 18px; height: 18px; animation: twinkle 3.8s ease-in-out infinite 1.6s; }
        @keyframes twinkle {
            0%, 100% { opacity: .35; transform: scale(.9) rotate(0deg); }
            50% { opacity: 1; transform: scale(1.12) rotate(22deg); }
        }

        /* paper airplane + dotted flight path */
        .plane-wrap { position: relative; height: 90px; width: 100%; max-width: 520px; margin: 6px auto 4px; }
        .dotted-path {
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            transform: translateY(-50%);
            border-top: 3px dotted rgba(67,16,159,.45);
            border-radius: 50%;
        }
        .paper-plane {
            position: absolute;
            top: 50%;
            right: 4%;
            transform: translateY(-50%);
            width: 42px;
            height: 42px;
            color: var(--violet);
            filter: drop-shadow(0 6px 12px rgba(67,16,159,.25));
            animation: planeFloat 4.5s ease-in-out infinite;
        }
        @keyframes planeFloat {
            0%, 100% { transform: translateY(-50%) translateX(0) rotate(-4deg); }
            50% { transform: translateY(calc(-50% - 8px)) translateX(-6px) rotate(6deg); }
        }

        .error-divider {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            margin: clamp(14px, 2vw, 22px) auto 6px;
            max-width: 420px;
        }
        .error-divider::before,
        .error-divider::after {
            content: "";
            flex: 1;
            height: 2px;
            background: linear-gradient(90deg, rgba(67,16,159,0), rgba(67,16,159,.4));
            border-radius: 2px;
        }
        .error-divider::after {
            background: linear-gradient(90deg, rgba(67,16,159,.4), rgba(67,16,159,0));
        }
        .error-divider span {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--brand);
            color: #fff;
            font-size: 20px;
            font-weight: 700;
            border-radius: 50%;
            box-shadow: 0 8px 18px rgba(67,16,159,.3);
        }

        .error-message {
            max-width: 560px;
            margin: 6px auto 0;
            color: #5B5873;
            font-size: clamp(15px, 1.7vw, 17px);
            line-height: 1.85;
        }

        /* ===== Animated scene (system UI style) ===== */
        .scene {
            position: relative;
            max-width: 760px;
            height: clamp(240px, 34vw, 360px);
            margin: clamp(16px, 2.5vw, 30px) auto 0;
            -webkit-user-select: none;
            user-select: none;
        }
        .orbit-stage {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* central "core" — glossy gradient sphere */
        .core {
            position: relative;
            width: clamp(96px, 13vw, 150px);
            height: clamp(96px, 13vw, 150px);
            border-radius: 50%;
            background:
                radial-gradient(circle at 30% 26%, rgba(255,255,255,.85) 0%, rgba(255,255,255,.1) 30%, rgba(255,255,255,0) 42%),
                radial-gradient(140% 140% at 50% 40%, #8B5CF6 0%, #5B21B6 58%, #321290 100%);
            box-shadow:
                0 20px 44px rgba(67,16,159,.42),
                inset 0 -16px 34px rgba(30,10,80,.55),
                inset 0 12px 26px rgba(255,255,255,.28);
            z-index: 2;
        }
        .core svg {
            position: absolute;
            inset: 0;
            margin: auto;
            width: 46%;
            height: 46%;
            color: #fff;
            filter: drop-shadow(0 2px 6px rgba(30,10,80,.5));
        }

        /* expanding pulse rings */
        .pulse-ring {
            position: absolute;
            left: 50%;
            top: 50%;
            width: clamp(96px, 13vw, 150px);
            height: clamp(96px, 13vw, 150px);
            transform: translate(-50%, -50%);
            border-radius: 50%;
            border: 2px solid rgba(124,58,237,.5);
            animation: pulseRing 3.6s ease-out infinite;
            z-index: 1;
        }
        .pulse-ring.r2 { animation-delay: 1.2s; }
        .pulse-ring.r3 { animation-delay: 2.4s; }
        @keyframes pulseRing {
            0%   { transform: translate(-50%, -50%) scale(.55); opacity: .9; }
            80%  { opacity: .12; }
            100% { transform: translate(-50%, -50%) scale(1.9); opacity: 0; }
        }

        /* rotating orbit rings */
        .orbit {
            position: absolute;
            left: 50%;
            top: 50%;
            border-radius: 50%;
            border: 1.5px solid rgba(124,58,237,.35);
            z-index: 1;
            transform: translate(-50%, -50%) rotate(-12deg);
        }
        .orbit.o1 { width: clamp(210px, 28vw, 320px); height: clamp(120px, 15vw, 170px); animation: spin1 16s linear infinite; }
        .orbit.o2 { width: clamp(150px, 20vw, 230px); height: clamp(76px, 10vw, 118px); animation: spin2 22s linear infinite; }
        @keyframes spin1 { to { transform: translate(-50%, -50%) rotate(348deg); } }
        @keyframes spin2 { to { transform: translate(-50%, -50%) rotate(-372deg); } }
        .orbit .sat {
            position: absolute;
            width: 11px;
            height: 11px;
            border-radius: 50%;
            background: rgba(124,58,237,.9);
            box-shadow: 0 0 0 4px rgba(124,58,237,.15), 0 4px 10px rgba(67,16,159,.35);
            left: 50%;
            top: 0;
            transform: translate(-50%, -50%);
        }
        .orbit.o1 .sat { background: #8B5CF6; }
        .orbit.o2 .sat { width: 8px; height: 8px; background: #A78BFA; }

        /* floating mini UI cards (KPI-style) */
        .float-card {
            position: absolute;
            background: rgba(255,255,255,.9);
            border: 1px solid rgba(196,181,253,.7);
            border-radius: 12px;
            box-shadow: 0 14px 30px rgba(67,16,159,.18);
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 9px;
            z-index: 3;
            animation: cardBob 5.5s ease-in-out infinite;
        }
        .float-card .fc-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--brand);
            flex-shrink: 0;
            box-shadow: 0 0 0 4px rgba(124,58,237,.15);
        }
        .float-card .fc-dot.green { background: #22C55E; box-shadow: 0 0 0 4px rgba(34,197,94,.15); }
        .float-card .fc-dot.orange { background: #F59E0B; box-shadow: 0 0 0 4px rgba(245,158,11,.15); }
        .float-card .fc-label { font-size: 11px; font-weight: 600; color: var(--ink); }
        @keyframes cardBob {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .fc-1 { left: 6%; top: 18%; animation-delay: .3s; }
        .fc-2 { right: 6%; top: 26%; animation-delay: 1.2s; }
        .fc-3 { left: 14%; bottom: 12%; animation-delay: 2s; }
        .fc-4 { right: 12%; bottom: 22%; animation-delay: .8s; }

        /* rising bubbles / particles */
        .bubble {
            position: absolute;
            border-radius: 50%;
            background: radial-gradient(circle at 32% 30%, rgba(255,255,255,.9), rgba(139,92,246,.55) 70%);
            box-shadow: 0 6px 14px rgba(67,16,159,.22);
            opacity: 0;
            z-index: 2;
            animation: bubbleRise 8s linear infinite;
        }
        @keyframes bubbleRise {
            0%   { transform: translateY(0) scale(.7); opacity: 0; }
            12%  { opacity: .85; }
            70%  { opacity: .7; }
            100% { transform: translateY(-150px) scale(1.15); opacity: 0; }
        }
        .b-1 { left: 20%; top: 70%; width: 12px; height: 12px; animation-delay: 0s; }
        .b-2 { left: 34%; top: 78%; width: 8px; height: 8px; animation-delay: 2.4s; }
        .b-3 { left: 64%; top: 74%; width: 14px; height: 14px; animation-delay: 1.2s; }
        .b-4 { left: 76%; top: 82%; width: 9px; height: 9px; animation-delay: 3.6s; }
        .b-5 { left: 48%; top: 86%; width: 10px; height: 10px; animation-delay: 4.8s; }

        /* dashed launcher arc behind the core */
        .scene-dash {
            position: absolute;
            left: 50%;
            top: 30%;
            width: 58%;
            height: 56%;
            transform: translateX(-50%);
            border-radius: 50%;
            border: 2.5px dashed rgba(124,58,237,.28);
            z-index: 0;
        }

        /* ===== Recovery + button ===== */
        .recovery {
            text-align: center;
            margin: clamp(26px, 4vw, 44px) auto 0;
            padding-bottom: 10px;
        }
        .recovery-title {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 0;
            font-size: clamp(20px, 3vw, 26px);
            font-weight: 800;
            letter-spacing: -0.01em;
            color: var(--ink);
        }
        .btn-dashboard {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 24px;
            height: 56px;
            padding: 0 42px;
            background: linear-gradient(160deg, #7C3AED 0%, #43109F 70%, #36088C 100%);
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            font-family: inherit;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            box-shadow: 0 14px 30px rgba(67,16,159,.32);
            transition: transform .2s ease, box-shadow .25s ease, background .25s ease;
        }
        .btn-dashboard svg { width: 19px; height: 19px; }
        .btn-dashboard:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 40px rgba(67,16,159,.42);
            background: linear-gradient(160deg, #8B5CF6 0%, #4F1AC0 70%, #3B0D96 100%);
        }
        .btn-dashboard:active { transform: translateY(0); box-shadow: 0 8px 18px rgba(67,16,159,.3); }
        .btn-dashboard:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }

        .support-msg {
            margin: 18px 0 0;
            font-size: 13.5px;
            color: var(--muted);
            line-height: 1.7;
        }

        /* ===== Responsive ===== */
        @media (max-width: 560px) {
            .dotted-path { border-top-width: 3px; }
            .plane-wrap { height: 74px; }
            .paper-plane { width: 34px; height: 34px; }
            .btn-dashboard { width: 100%; max-width: 360px; padding: 0 26px; }
        }
        @media (max-width: 420px) {
            .fc-1, .fc-2, .fc-3, .fc-4 { display: none; }
            .scene { height: clamp(200px, 42vw, 260px); }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>
<body class="page-404">

    <main class="error-main">
        <div class="container">
            <p class="error-kicker"><b>Oops!</b> You&rsquo;ve found a page that doesn&rsquo;t exist.</p>

            <div class="big-404" aria-hidden="true">
                4<span class="zero">
                    0
                    <span class="face-in-zero">
                        <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="20" cy="27" r="3.6" fill="currentColor" stroke="none"></circle>
                            <circle cx="44" cy="27" r="3.6" fill="currentColor" stroke="none"></circle>
                            <path d="M24 43 Q32 36 40 43"></path>
                        </svg>
                    </span>
                </span>4
                <span class="sparkle sparkle-1">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0l2.4 9.6L24 12l-9.6 2.4L12 24l-2.4-9.6L0 12l9.6-2.4z"/></svg>
                </span>
                <span class="sparkle sparkle-2">
                    <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="6"/></svg>
                </span>
                <span class="sparkle sparkle-3">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 0l2.4 9.6L24 12l-9.6 2.4L12 24l-2.4-9.6L0 12l9.6-2.4z"/></svg>
                </span>
                <span class="sparkle sparkle-4">
                    <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="6"/></svg>
                </span>
            </div>

            <div class="plane-wrap">
                <div class="dotted-path"></div>
                <svg class="paper-plane" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"></path>
                </svg>
            </div>

            <div class="error-divider"><span>&times;</span></div>

            <p class="error-message">The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.</p>

            <div class="scene">
                <div class="scene-dash" aria-hidden="true"></div>
                <div class="orbit-stage">
                    <span class="pulse-ring"></span>
                    <span class="pulse-ring r2"></span>
                    <span class="pulse-ring r3"></span>

                    <div class="orbit o1"><span class="sat"></span></div>
                    <div class="orbit o2"><span class="sat"></span></div>

                    <div class="core" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"/>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </div>

                    <div class="float-card fc-1">
                        <span class="fc-dot"></span>
                        <span class="fc-label">Searching...</span>
                    </div>
                    <div class="float-card fc-2">
                        <span class="fc-dot green"></span>
                        <span class="fc-label">Online</span>
                    </div>
                    <div class="float-card fc-3">
                        <span class="fc-dot orange"></span>
                        <span class="fc-label">No results</span>
                    </div>
                    <div class="float-card fc-4">
                        <span class="fc-dot"></span>
                        <span class="fc-label">Retry</span>
                    </div>

                    <span class="bubble b-1"></span>
                    <span class="bubble b-2"></span>
                    <span class="bubble b-3"></span>
                    <span class="bubble b-4"></span>
                    <span class="bubble b-5"></span>
                </div>
            </div>

            <section class="recovery">
                <h1 class="recovery-title">
                    Don&rsquo;t worry, let&rsquo;s get you back on track!
                </h1>
                <div>
                    <a href="<?= e($dashboardUrl) ?>" class="btn-dashboard">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                        Go to Dashboard
                    </a>
                </div>
                <p class="support-msg">.</p>
            </section>
        </div>
    </main>

    <script>
    (function () {
        var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced) return;
        document.addEventListener('DOMContentLoaded', function () {
            document.body.classList.add('loaded');
        });
    })();
    </script>
</body>
</html>
