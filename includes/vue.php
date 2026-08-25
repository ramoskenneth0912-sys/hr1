<?php
declare(strict_types=1);

/**
 * HR1 + Vue bridge (SPA pattern).
 *
 * Resolves built Vite assets through manifest.json so hashed filenames
 * never need hardcoding in admin.php. While developing, fall back to the
 * Vite dev server (npm run vue:dev) for hot module replacement.
 *
 * Usage inside admin.php:
 *   require_once __DIR__ . '/includes/vue.php';
 *   echo vue_asset_tags();
 */
function vue_asset_tags(): string
{
    static $manifest = null;
    if ($manifest === null) {
        $file = __DIR__ . '/../assets/vue/.vite/manifest.json';
        $manifest = is_file($file)
            ? (json_decode((string) file_get_contents($file), true) ?: [])
            : [];
    }

    $key = 'index.html';
    if (isset($manifest[$key]['file'])) {
        $base = (defined('BASE_URL') ? BASE_URL : '/HR1') . '/assets/vue/';
        $html = '';
        foreach (($manifest[$key]['css'] ?? []) as $css) {
            $html .= '<link rel="stylesheet" href="' . htmlspecialchars($base . $css, ENT_QUOTES) . '">' . "\n";
        }
        foreach (($manifest[$key]['imports'] ?? []) as $import) {
            if (isset($manifest[$import]['file'])) {
                $html .= '<link rel="modulepreload" href="' . htmlspecialchars($base . $manifest[$import]['file'], ENT_QUOTES) . '">' . "\n";
            }
        }
        $html .= '<script type="module" src="' . htmlspecialchars($base . $manifest[$key]['file'], ENT_QUOTES) . '"></script>';
        return $html;
    }

    // Built assets missing → dev mode. Start with: npm run vue:dev
    return '<script type="module" src="http://localhost:5174/@vite/client"></script>' . "\n"
         . '<script type="module" src="http://localhost:5174/src/main.js"></script>';
}
