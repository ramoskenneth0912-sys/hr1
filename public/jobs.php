<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security_headers.php';

if (isLoggedIn() && isEmployee()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$search = trim($_GET['search'] ?? '');
$locationFilter = trim($_GET['location'] ?? '');
$categoryFilter = $_GET['category'] ?? '';

$departments = getDepartments();

$locations = db()->query(
    "SELECT DISTINCT work_location FROM job_postings
     WHERE status = 'open' AND work_location IS NOT NULL AND work_location <> ''
     ORDER BY work_location"
)->fetchAll(PDO::FETCH_COLUMN);

$totalOpen = (int) db()->query(
    "SELECT COUNT(*) FROM job_postings WHERE status = 'open'"
)->fetchColumn();

$sql = 'SELECT j.*, d.name AS department_name
        FROM job_postings j
        LEFT JOIN departments d ON j.department_id = d.id
        WHERE j.status = ?';
$params = ['open'];

if ($search !== '') {
    $sql .= ' AND (j.title LIKE ? OR j.description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($locationFilter !== '') {
    $sql .= ' AND j.work_location = ?';
    $params[] = $locationFilter;
}
if ($categoryFilter !== '') {
    $sql .= ' AND j.department_id = ?';
    $params[] = $categoryFilter;
}

$sql .= ' ORDER BY j.posted_date DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$employmentLabels = [
    'regular'       => 'Full-time',
    'contractual'   => 'Contractual',
    'probationary'  => 'Probationary',
    'part_time'     => 'Part-time',
    'internship'    => 'Internship',
];

$hasFilters = ($search !== '' || $locationFilter !== '' || $categoryFilter !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Open Positions - <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: #43109F;
            --brand-dark: #36088C;
            --ink: #12102E;
            --muted: #6B7280;
            --faint: #9CA3AF;
            --line: #E7E5EE;
            --lavender: #EDE4FA;
            --lavender-deep: #E5D5FA;
            --hero-top: #F7F6FC;
            --footer-bg: #351286;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; background: #fff; }
        html, body { overflow-x: clip; }
        body.public-body {
            font-family: 'Inter', sans-serif;
            color: var(--ink);
            background: transparent;
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        body.public-body::before {
            content: "";
            position: fixed;
            inset: -12%;
            z-index: -1;
            pointer-events: none;
            background:
                radial-gradient(38% 32% at 14% 10%, rgba(229,213,250,.42), rgba(229,213,250,0) 70%),
                radial-gradient(30% 26% at 82% 16%, rgba(237,228,250,.38), rgba(237,228,250,0) 72%),
                radial-gradient(34% 30% at 78% 84%, rgba(67,16,159,.07), rgba(67,16,159,0) 70%);
            animation: auraDrift 80s ease-in-out infinite alternate;
            will-change: transform;
        }
        @keyframes auraDrift {
            0%   { transform: translate3d(0, 0, 0) scale(1); }
            50%  { transform: translate3d(-1.6%, 1.2%, 0) scale(1.05); }
            100% { transform: translate3d(1.4%, -1%, 0) scale(1.02); }
        }
        .sr-only {
            position: absolute; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0,0,0,0); white-space: nowrap; border: 0;
        }
        .skip-link {
            position: absolute; left: 16px; top: -48px; z-index: 100;
            background: var(--brand); color: #fff; padding: 10px 18px;
            border-radius: 8px; font-size: 14px; font-weight: 600;
            text-decoration: none; transition: top .2s ease;
        }
        .skip-link:focus { top: 12px; }
        .container {
            width: 100%;
            margin: 0 auto;
            padding-left: clamp(20px, 5.75vw, 89px);
            padding-right: clamp(20px, 5.75vw, 89px);
        }
        .container-wide {
            padding-left: clamp(20px, 3.3vw, 51px);
            padding-right: clamp(20px, 3.3vw, 51px);
        }

        /* ===== Header ===== */
        .site-header {
            background: #fff;
            min-height: 112px;
            display: flex;
            align-items: center;
            position: relative;
            z-index: 20;
        }
        .site-header .container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
        }
        .brand { display: inline-flex; align-items: center; flex-shrink: 0; }
        .brand img { height: 92px; width: auto; display: block; }
        .header-right { display: flex; align-items: center; gap: clamp(20px, 3.9vw, 60px); }
        .main-nav { display: flex; align-items: center; gap: clamp(20px, 3.9vw, 60px); }
        .main-nav a {
            font-size: 15px;
            font-weight: 500;
            color: #3F3D56;
            text-decoration: none;
            transition: color .2s ease;
        }
        .main-nav a:hover, .main-nav a.active { color: var(--brand); }
        .btn-login {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 48px;
            padding: 0 34px;
            background: var(--brand);
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            border-radius: 10px;
            text-decoration: none;
            white-space: nowrap;
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }
        .btn-login:hover {
            background: var(--brand-dark);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(67,16,159,.22);
        }
        .btn-login:active { transform: translateY(0); box-shadow: none; }
        .nav-toggle {
            display: none;
            width: 46px; height: 46px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
            padding: 0;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 5px;
            transition: border-color .2s ease, background .2s ease;
        }
        .nav-toggle:hover { border-color: #C9B8F0; background: #FAF7FF; }
        .nav-toggle span {
            display: block; width: 20px; height: 2px;
            background: var(--ink); border-radius: 2px;
            transition: transform .25s ease, opacity .25s ease;
        }
        .nav-toggle[aria-expanded="true"] span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .nav-toggle[aria-expanded="true"] span:nth-child(2) { opacity: 0; }
        .nav-toggle[aria-expanded="true"] span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
        .mobile-nav {
            display: none;
            background: #fff;
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            padding: 10px clamp(20px, 3.3vw, 51px) 18px;
        }
        .mobile-nav a {
            display: block;
            padding: 13px 4px;
            font-size: 15px;
            font-weight: 500;
            color: #3F3D56;
            text-decoration: none;
            border-bottom: 1px solid #F1EFF7;
            transition: color .15s ease, padding-left .2s ease;
        }
        .mobile-nav a:hover { color: var(--brand); padding-left: 10px; }
        .mobile-nav a:last-child { border-bottom: none; }

        /* ===== Hero ===== */
        .hero {
            position: relative;
            height: 288px;
            background:
                radial-gradient(90% 170% at 6% 120%, var(--lavender-deep) 0%, rgba(229,213,250,0) 55%),
                linear-gradient(180deg, var(--hero-top) 0%, #F7F5FC 55%, #F4EDFB 100%);
            overflow: hidden;
        }
        .hero .container {
            position: relative;
            z-index: 2;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            padding-top: 46px;
        }
        .hero-globe {
            position: absolute;
            top: 0;
            right: 0;
            height: 100%;
            width: auto;
            max-width: 62vw;
            object-fit: cover;
            object-position: left top;
            z-index: 1;
            pointer-events: none;
            animation: globeFloat 9s ease-in-out infinite;
            will-change: transform;
        }
        @keyframes globeFloat {
            0%, 100% { transform: translateY(0); }
            50%      { transform: translateY(-8px); }
        }
        .hero h1 {
            margin: 0;
            font-size: clamp(30px, 2.95vw, 45px);
            line-height: 1.15;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: var(--ink);
        }
        .hero-sub {
            margin: 20px 0 0;
            max-width: 530px;
            font-size: 16px;
            line-height: 1.85;
            color: #7A7590;
        }
        .btn-hero {
            margin-top: 26px;
            align-self: flex-start;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            height: 58px;
            padding: 0 38px;
            background: var(--brand);
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            border-radius: 10px;
            text-decoration: none;
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }
        .btn-hero:hover {
            background: var(--brand-dark);
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(67,16,159,.28);
        }
        .btn-hero:active { transform: translateY(0); box-shadow: 0 6px 14px rgba(67,16,159,.2); }

        /* ===== Search panel ===== */
        .search-zone { position: relative; }
        .blob {
            position: absolute;
            width: 220px;
            height: 120px;
            filter: blur(42px);
            opacity: .55;
            pointer-events: none;
        }
        .blob-left  { left: -80px;  top: -30px; background: var(--lavender-deep); animation: blobDrift 46s ease-in-out infinite alternate; }
        .blob-right { right: -80px; top: -30px; background: #EFE0FC; animation: blobDrift 58s ease-in-out infinite alternate-reverse; }
        @keyframes blobDrift {
            0%   { transform: translate3d(0, 0, 0) scale(1); }
            100% { transform: translate3d(26px, -14px, 0) scale(1.12); }
        }
        .search-panel {
            position: relative;
            z-index: 5;
            margin-top: 16px;
            display: flex;
            gap: 14px;
        }
        .search-panel input,
        .search-panel select {
            height: 48px;
            padding: 0 16px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            font-family: inherit;
            font-size: 14px;
            color: var(--ink);
            transition: border-color .25s ease, box-shadow .25s ease, transform .25s ease;
        }
        .search-panel input { flex: 1.55; min-width: 180px; }
        .search-panel select { flex: 1; min-width: 140px; cursor: pointer; }
        .search-panel input:hover,
        .search-panel select:hover { border-color: #D8CCEF; }
        .search-panel input:focus,
        .search-panel select:focus {
            outline: none;
            border-color: #A87FE8;
            box-shadow: 0 0 0 4px rgba(67,16,159,.1);
            transform: translateY(-1px);
        }
        .btn-search {
            height: 48px;
            padding: 0 44px;
            background: var(--brand);
            color: #fff;
            font-family: inherit;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background .2s ease, transform .2s ease, box-shadow .2s ease;
        }
        .btn-search:hover {
            background: var(--brand-dark);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(67,16,159,.22);
        }
        .btn-search:active { transform: translateY(0); box-shadow: none; }
        .clear-filters {
            align-self: center;
            flex-shrink: 0;
            font-size: 14px;
            font-weight: 600;
            color: var(--brand);
            text-decoration: none;
            padding: 8px 4px;
            transition: color .15s ease;
        }
        .clear-filters:hover { color: var(--brand-dark); text-decoration: underline; }

        /* ===== Jobs section ===== */
        .jobs-section { padding: 50px 0 0; scroll-margin-top: 16px; }
        .jobs-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
        }
        .jobs-head h2 {
            margin: 0;
            font-size: 23px;
            font-weight: 700;
            letter-spacing: -0.01em;
            color: var(--ink);
        }
        .jobs-head .jobs-sub {
            margin: 8px 0 0;
            font-size: 15px;
            color: var(--muted);
        }
        .jobs-count {
            font-size: 14px;
            color: var(--muted);
            white-space: nowrap;
            padding-bottom: 2px;
        }
        .job-grid {
            margin-top: 22px;
            display: grid;
            grid-template-columns: 1fr;
            gap: 17px;
        }
        .job-banner {
            position: relative;
            display: block;
            text-decoration: none;
            height: 320px;
            border-radius: 14px;
            overflow: hidden;
            background: var(--lavender);
            box-shadow: 0 1px 2px rgba(18,16,46,.06);
            transition: box-shadow .3s ease, transform .3s ease;
            container-type: size;
        }
        .job-banner:focus-visible {
            outline: 3px solid var(--brand);
            outline-offset: 3px;
        }
        .job-banner:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 26px rgba(18,16,46,.12);
        }
        .job-banner-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform .5s ease;
        }
        .job-banner:hover .job-banner-img { transform: scale(1.045); }
        .job-banner-shade {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(53,18,134,.82) 0%, rgba(67,16,159,.62) 24%, rgba(67,16,159,.26) 46%, rgba(67,16,159,0) 64%);
        }
        .job-banner-text {
            position: absolute;
            left: 36px;
            right: 36px;
            top: 50%;
            transform: translateY(-50%);
            max-width: min(56%, 520px);
            z-index: 1;
        }
        .job-banner-title {
            display: block;
            margin: 0;
            color: #fff;
            font-size: 23px;
            font-weight: 700;
            letter-spacing: .01em;
            line-height: 1.25;
            text-shadow: 0 1px 12px rgba(20,6,60,.45);
        }
        .job-banner-subtitle {
            display: block;
            margin-top: 8px;
            color: rgba(255,255,255,.92);
            font-size: 15px;
            font-weight: 500;
            line-height: 1.55;
            text-shadow: 0 1px 10px rgba(20,6,60,.5);
        }
        .btn-outline, .btn-solid {
            flex: 1;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none;
            transition: all .2s ease;
        }
        .btn-outline {
            border: 1px solid #D8D4E8;
            color: var(--brand);
            background: #fff;
        }
        .btn-outline:hover {
            border-color: var(--brand);
            background: #FAF7FF;
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(67,16,159,.1);
        }
        .btn-solid { background: var(--brand); color: #fff; }
        .btn-solid:hover {
            background: var(--brand-dark);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(67,16,159,.22);
        }

        .empty-state {
            margin-top: 22px;
            text-align: center;
            padding: 56px 16px;
            border: 1px dashed var(--line);
            border-radius: 12px;
            color: var(--muted);
        }
        .empty-state a { color: var(--brand); font-weight: 600; }

        /* ===== About / Contact ===== */
        .info-section { padding: 64px 0 0; scroll-margin-top: 16px; }
        .info-panel {
            background:
                radial-gradient(70% 140% at 100% 0%, rgba(229,213,250,.55) 0%, rgba(229,213,250,0) 60%),
                linear-gradient(180deg, #FBFAFE 0%, #F6F2FC 100%);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: clamp(26px, 3.2vw, 48px);
        }
        .info-kicker {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--brand);
        }
        .info-panel h2 {
            margin: 10px 0 0;
            font-size: clamp(21px, 1.8vw, 27px);
            font-weight: 800;
            letter-spacing: -0.01em;
            color: var(--ink);
        }
        .info-panel .info-text {
            margin: 14px 0 0;
            max-width: 860px;
            font-size: 15px;
            line-height: 1.9;
            color: var(--muted);
        }
        .about-points {
            margin-top: 26px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }
        .point-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px;
            transition: transform .25s ease, box-shadow .25s ease;
        }
        .point-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 26px rgba(18,16,46,.08);
        }
        .point-card h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--ink);
        }
        .point-card p {
            margin: 8px 0 0;
            font-size: 13.5px;
            line-height: 1.7;
            color: var(--muted);
        }
        .contact-grid {
            margin-top: 26px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }
        .contact-card {
            display: block;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 20px;
            text-decoration: none;
            transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }
        .contact-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 26px rgba(18,16,46,.08);
            border-color: #DDD2F2;
        }
        .contact-card .cc-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--brand);
        }
        .contact-card .cc-value {
            display: block;
            margin-top: 8px;
            font-size: 15px;
            font-weight: 600;
            color: var(--ink);
            word-break: break-word;
        }
        .contact-card .cc-hint {
            display: block;
            margin-top: 4px;
            font-size: 13px;
            color: var(--muted);
        }

        /* ===== Footer ===== */
        .site-footer {
            position: relative;
            margin-top: 64px;
            background: var(--footer-bg);
            color: #fff;
            overflow: hidden;
        }
        .site-footer::before,
        .site-footer::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }
        .site-footer::before {
            width: 480px; height: 300px;
            left: -140px; top: -170px;
            background: radial-gradient(closest-side, rgba(229,213,250,.16), rgba(229,213,250,0));
            animation: footerGlow 52s ease-in-out infinite alternate;
        }
        .site-footer::after {
            width: 420px; height: 280px;
            right: -120px; bottom: -180px;
            background: radial-gradient(closest-side, rgba(159,109,222,.28), rgba(159,109,222,0));
            animation: footerGlow 64s ease-in-out infinite alternate-reverse;
        }
        @keyframes footerGlow {
            0%   { transform: translate3d(0, 0, 0) scale(1); }
            100% { transform: translate3d(30px, 18px, 0) scale(1.15); }
        }
        .site-footer .container {
            position: relative;
            z-index: 1;
            min-height: 128px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            padding-top: 22px;
            padding-bottom: 22px;
        }
        .footer-brand {
            display: flex;
            align-items: center;
            gap: 18px;
            min-width: 0;
        }
        .footer-brand img {
            height: 68px;
            width: auto;
            display: block;
            box-sizing: content-box;
            padding: 9px 14px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(18,16,46,.22);
        }
        .footer-divider {
            width: 1px;
            align-self: stretch;
            margin: 6px 4px;
            background: linear-gradient(180deg, rgba(255,255,255,0), rgba(255,255,255,.32) 30%, rgba(255,255,255,.32) 70%, rgba(255,255,255,0));
        }
        .footer-brand-text { line-height: 1.4; }
        .footer-app {
            display: block;
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: rgba(255,255,255,.58);
        }
        .footer-brand-text strong {
            display: block;
            margin-top: 2px;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: .02em;
        }
        .footer-tagline {
            font-size: 13px;
            color: rgba(255,255,255,.78);
        }
        .footer-copy {
            font-size: 12.5px;
            color: rgba(255,255,255,.55);
            text-align: center;
        }
        .footer-social {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .footer-social a {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,.28);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            transition: background .2s ease, transform .2s ease;
        }
        .footer-social a:hover {
            background: rgba(255,255,255,.14);
            transform: translateY(-2px);
        }

        /* ===== Animations ===== */
        .fade-up {
            opacity: 0;
            transform: translateY(16px);
            animation: fadeUp .7s cubic-bezier(.22,.61,.36,1) forwards;
            animation-delay: var(--d, 0s);
        }
        @keyframes fadeUp {
            to { opacity: 1; transform: translateY(0); }
        }
        .js .reveal {
            opacity: 0;
            transform: translateY(18px);
            transition: opacity .65s ease, transform .65s ease;
            transition-delay: var(--rd, 0s);
        }
        .js .reveal.in-view {
            opacity: 1;
            transform: translateY(0);
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
            .fade-up, .js .reveal {
                opacity: 1 !important;
                transform: none !important;
                animation: none !important;
            }
            .hero-globe { animation: none !important; }
        }

        /* ===== Responsive ===== */
        @media (max-width: 1100px) {
            .job-banner { height: 288px; }
            .hero-globe { opacity: .5; max-width: 52vw; }
            .about-points, .contact-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 900px) {
            .about-points, .contact-grid { grid-template-columns: 1fr 1fr; }
            .site-footer .container { flex-wrap: wrap; justify-content: center; text-align: center; }
            .footer-brand { flex-direction: column; gap: 12px; }
            .footer-brand img { height: 56px; padding: 7px 11px; }
            .footer-divider { display: none; }
            .footer-brand-text strong { margin-top: 0; }
            .footer-copy { order: 3; width: 100%; }
        }
        @media (max-width: 820px) {
            .site-header { min-height: 88px; }
            .brand img { height: 64px; }
            .main-nav { display: none; }
            .nav-toggle { display: inline-flex; }
            .mobile-nav:not([hidden]) { display: block; }
            .hero { height: auto; padding-bottom: 40px; }
            .hero .container { padding-top: 34px; }
            .hero-globe { opacity: .38; max-width: 68vw; }
            .btn-login { height: 44px; padding: 0 24px; }
        }
        @media (max-width: 760px) {
            .hero h1 { font-size: 32px; }
            .search-panel { flex-wrap: wrap; }
            .search-panel input, .search-panel select { flex: 1 1 100%; }
            .btn-search { flex: 1 1 100%; }
            .clear-filters { flex: 1 1 100%; text-align: center; }
            .job-banner { height: 252px; }
            .job-banner-text { left: 26px; right: 26px; max-width: 82%; }
            .job-banner-title { font-size: 20px; }
            .job-banner-subtitle { font-size: 14px; }
            .jobs-head { flex-direction: column; align-items: flex-start; }
            .about-points, .contact-grid { grid-template-columns: 1fr; }
            .info-section { padding-top: 44px; }
        }
        @media (max-width: 480px) {
            .brand img { height: 54px; }
            .site-header { min-height: 76px; }
            .hero h1 { font-size: 27px; }
            .hero-sub { font-size: 15px; }
            .btn-hero { height: 52px; padding: 0 30px; }
            .job-banner { height: 224px; border-radius: 12px; }
            .job-banner-text { left: 22px; right: 22px; max-width: none; }
            .job-banner-title { font-size: 18px; }
            .job-banner-subtitle { font-size: 13px; margin-top: 6px; }
            .footer-brand img { height: 62px; max-width: 86vw; }
        }
        @media (max-width: 560px) {
            .site-header .btn-login { display: none; }
        }
    </style>
</head>
<body class="public-body">
    <a class="skip-link" href="#jobs">Skip to job listings</a>
    <header class="site-header">
        <div class="container container-wide">
            <a href="<?= BASE_URL ?>/public/jobs.php" class="brand" aria-label="TRI-M Global Logistics &amp; Trading Inc. — Home">
                <img src="../assets/images/tri-m-logo.png" alt="TRI-M GLOBAL — Logistics &amp; Trading Inc.">
            </a>
            <div class="header-right">
                <nav class="main-nav" aria-label="Main navigation">
                    <a href="<?= BASE_URL ?>/public/jobs.php" class="active">Home</a>
                    <a href="#about">About Us</a>
                    <a href="#contact">Contact Us</a>
                </nav>
                <?php if (isLoggedIn()): ?>
                    <a href="<?= BASE_URL ?>/index.php" class="btn-login">Dashboard</a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>/auth/login.php" class="btn-login">Log In</a>
                <?php endif; ?>
                <button type="button" class="nav-toggle" id="navToggle" aria-expanded="false" aria-controls="mobileNav" aria-label="Toggle menu">
                    <span></span><span></span><span></span>
                </button>
            </div>
        </div>
        <nav class="mobile-nav" id="mobileNav" hidden aria-label="Mobile navigation">
            <a href="<?= BASE_URL ?>/public/jobs.php">Home</a>
            <a href="#about">About Us</a>
            <a href="#contact">Contact Us</a>
            <?php if (isLoggedIn()): ?>
                <a href="<?= BASE_URL ?>/index.php">Dashboard</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>/auth/login.php">Log In</a>
            <?php endif; ?>
        </nav>
    </header>

    <section class="hero">
        <img src="../assets/images/hero-globe.png" alt="" class="hero-globe" aria-hidden="true">
        <div class="container">
            <h1 class="fade-up">Find Your Next Opportunity</h1>
            <p class="hero-sub fade-up" style="--d:.12s">Explore available positions and find a job that matches your skills and experience.</p>
            <a href="<?= BASE_URL ?>/public/browse-jobs.php" class="btn-hero fade-up" style="--d:.24s">Browse Jobs &rarr;</a>
        </div>
    </section>

    <div class="search-zone">
        <span class="blob blob-left" aria-hidden="true"></span>
        <span class="blob blob-right" aria-hidden="true"></span>
        <form method="get" action="jobs.php#jobs" class="container search-panel">
            <input type="text" name="search" placeholder="Search by job title or keyword" value="<?= e($search) ?>" aria-label="Search by job title or keyword">
            <select name="location" aria-label="Location" data-autosubmit>
                <option value="">Location</option>
                <?php foreach ($locations as $loc): ?>
                    <option value="<?= e($loc) ?>" <?= $locationFilter === $loc ? 'selected' : '' ?>><?= e($loc) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="category" aria-label="Job Category" data-autosubmit>
                <option value="">Job Category</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= e((string)$dept['id']) ?>" <?= $categoryFilter == $dept['id'] ? 'selected' : '' ?>><?= e($dept['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-search">Search</button>
            <?php if ($hasFilters): ?>
                <a href="jobs.php" class="clear-filters">Clear filters &times;</a>
            <?php endif; ?>
        </form>
    </div>

    <main class="jobs-section" id="jobs">
        <div class="container">
            <div class="jobs-head reveal">
                <div>
                    <h2>Available Job Opportunities</h2>
                    <p class="jobs-sub">Explore and apply for available positions that match your skills.</p>
                </div>
                <span class="jobs-count">Showing <?= count($jobs) ?> of <?= $totalOpen ?> jobs</span>
            </div>

            <?php if (empty($jobs)): ?>
                <div class="empty-state reveal in-view">
                    <p><strong>No open positions at this time.</strong></p>
                    <p>Check back later for new opportunities.</p>
                    <?php if ($hasFilters): ?>
                        <p><a href="jobs.php">Clear search filters</a></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="job-grid">
                    <?php
                        $jobImageMap = [
                            'marketing'    => [
                                'img' => '../assets/images/job-marketing.png',
                                'alt' => 'Marketing specialist working in a creative office',
                                'title' => 'Marketing Specialist',
                                'subtitle' => 'Drive brand growth, campaigns, and customer engagement.',
                            ],
                            'operation'    => [
                                'img' => '../assets/images/job-operations.png',
                                'alt' => 'Operations coordinator working in a logistics warehouse',
                                'title' => 'Operations Coordinator',
                                'subtitle' => 'Coordinate daily operations and keep business processes running smoothly.',
                            ],
                            'it support'   => [
                                'img' => '../assets/images/job-it-support.png',
                                'alt' => 'IT support specialist working with computer and server equipment',
                                'title' => 'IT Support Specialist',
                                'subtitle' => 'Provide technical support and keep systems, devices, and users connected.',
                            ],
                            'finance'      => [
                                'img' => '../assets/images/job-finance.png',
                                'alt' => 'Finance analyst analyzing financial data in an office',
                                'title' => 'Finance Analyst',
                                'subtitle' => 'Analyze financial data and support smarter business decisions.',
                            ],
                            'hr assistant' => [
                                'img' => '../assets/images/job-hr-assistant.png',
                                'alt' => 'HR assistant assisting with an interview in an HR meeting room',
                                'title' => 'HR Assistant',
                                'subtitle' => 'Support employee services, recruitment, and essential HR operations.',
                            ],
                        ];
                        $fallbackImg = ['img' => '../assets/images/job-operations.png', 'alt' => 'Professional at work in a modern workplace'];
                    ?>
                    <?php foreach ($jobs as $i => $job): ?>
                        <?php
                            $titleLower = mb_strtolower((string)$job['title']);
                            $imgMeta = $fallbackImg;
                            foreach ($jobImageMap as $needle => $meta) {
                                if (mb_stripos($titleLower, $needle) !== false) { $imgMeta = $meta; break; }
                            }
                        ?>
                        <a class="job-banner reveal" href="job_detail.php?id=<?= (int)$job['id'] ?>" style="--rd: <?= number_format($i * 0.06, 2) ?>s" aria-label="<?= e($job['title']) ?> — view details and apply">
                            <img class="job-banner-img" src="<?= e($imgMeta['img']) ?>" alt="<?= e($imgMeta['alt']) ?>">
                            <div class="job-banner-shade" aria-hidden="true"></div>
                            <?php if (!empty($imgMeta['title'])): ?>
                                <span class="job-banner-text">
                                    <span class="job-banner-title"><?= e($imgMeta['title']) ?></span>
                                    <?php if (!empty($imgMeta['subtitle'])): ?>
                                        <span class="job-banner-subtitle"><?= e($imgMeta['subtitle']) ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <section class="info-section" id="about">
        <div class="container">
            <div class="info-panel reveal">
                <p class="info-kicker">About Us</p>
                <h2>TRI-M Global Logistics &amp; Trading Inc.</h2>
                <p class="info-text">
                    TRI-M Global Logistics &amp; Trading Inc. is a Philippines-based wholesale import and export company.
                    Since 2008, we have been moving quality products across borders — combining dependable logistics with
                    honest trading — while building a workplace where people grow and careers thrive.
                </p>
                <div class="about-points">
                    <div class="point-card">
                        <h3>Global Reach</h3>
                        <p>A worldwide import and export network that connects Filipino products to international markets.</p>
                    </div>
                    <div class="point-card">
                        <h3>Reliable Logistics</h3>
                        <p>Efficient, end-to-end freight and trading services built on simplicity and competitive pricing.</p>
                    </div>
                    <div class="point-card">
                        <h3>People First</h3>
                        <p>We invest in our team — every hire joins a culture that values growth, integrity, and teamwork.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="info-section" id="contact">
        <div class="container">
            <div class="info-panel reveal">
                <p class="info-kicker">Contact Us</p>
                <h2>Get in Touch</h2>
                <p class="info-text">
                    Questions about a position or the application process? Reach out through any of the channels below
                    and our team will get back to you.
                </p>
                <div class="contact-grid">
                    <a href="mailto:info@tri-mglobal.com" class="contact-card">
                        <span class="cc-label">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                            Email
                        </span>
                        <span class="cc-value">info@tri-mglobal.com</span>
                        <span class="cc-hint">Send us your questions anytime</span>
                    </a>
                    <a href="https://tmglt.com/" target="_blank" rel="noopener" class="contact-card">
                        <span class="cc-label">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                            Website
                        </span>
                        <span class="cc-value">tmglt.com</span>
                        <span class="cc-hint">Learn more about our business</span>
                    </a>
                    <a href="https://www.linkedin.com/company/tri-m-global-logistics-trading-inc" target="_blank" rel="noopener" class="contact-card">
                        <span class="cc-label">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4V8h4v1.5A6 6 0 0 1 16 8z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
                            LinkedIn
                        </span>
                        <span class="cc-value">TRI-M Global Logistics &amp; Trading, Inc.</span>
                        <span class="cc-hint">Follow our company updates</span>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <footer class="site-footer">
        <div class="container container-wide">
            <div class="footer-brand">
                <img src="../assets/images/tri-m-logo.png" alt="TRI-M GLOBAL — Logistics &amp; Trading Inc.">
                <span class="footer-divider" aria-hidden="true"></span>
                <div class="footer-brand-text">
                    <span class="footer-app">Merchandising Management System</span>
                    <strong>TRI-M GLOBAL</strong>
                    <span class="footer-tagline">Building connections. Delivering excellence.</span>
                </div>
            </div>
            <span class="footer-copy">&copy; <?= date('Y') ?> TRI-M Global Logistics &amp; Trading Inc. All rights reserved.</span>
            <div class="footer-social">
                <a href="https://tmglt.com/" target="_blank" rel="noopener" aria-label="Website">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                </a>
                <a href="https://www.linkedin.com/company/tri-m-global-logistics-trading-inc" target="_blank" rel="noopener" aria-label="LinkedIn">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4V8h4v1.5A6 6 0 0 1 16 8z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
                </a>
                <a href="mailto:info@tri-mglobal.com" aria-label="Email">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                </a>
            </div>
        </div>
    </footer>

    <script>
    (function () {
        document.documentElement.classList.add('js');
        var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        var toggle = document.getElementById('navToggle');
        var mobileNav = document.getElementById('mobileNav');
        if (toggle && mobileNav) {
            toggle.addEventListener('click', function () {
                var open = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!open));
                mobileNav.hidden = open;
            });
            mobileNav.addEventListener('click', function (e) {
                if (e.target.tagName === 'A') {
                    toggle.setAttribute('aria-expanded', 'false');
                    mobileNav.hidden = true;
                }
            });
        }

        document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
            sel.addEventListener('change', function () { sel.form.submit(); });
        });

        var reveals = document.querySelectorAll('.reveal');
        if (reduced || !('IntersectionObserver' in window)) {
            reveals.forEach(function (el) { el.classList.add('in-view'); });
        } else {
            var io = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('in-view');
                        io.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -30px 0px' });
            reveals.forEach(function (el) { io.observe(el); });
        }
    })();
    </script>
</body>
</html>
