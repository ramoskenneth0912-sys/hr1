<?php
declare(strict_types=1);

/**
 * HR1 + React bridge (island pattern).
 *
 * Resolves built Vite assets through manifest.json so hashed filenames
 * never need hardcoding in PHP pages. While developing, fall back to the
 * Vite dev server (npm run react:dev) for hot module replacement.
 *
 * Usage inside any PHP page:
 *   require_once __DIR__ . '/includes/react.php';
 *   echo react_asset_tags('main');
 *
 * Then place a mount point anywhere in the markup:
 *   <div data-react-widget="open-positions"></div>
 */
function react_asset_tags(string $entry = 'main'): string
{
    static $manifest = null;
    if ($manifest === null) {
        $file = __DIR__ . '/../assets/react/.vite/manifest.json';
        $manifest = is_file($file)
            ? (json_decode((string) file_get_contents($file), true) ?: [])
            : [];
    }

    $key = 'src/' . $entry . '.jsx';
    if (isset($manifest[$key]['file'])) {
        $base = (defined('BASE_URL') ? BASE_URL : '/HR1') . '/assets/react/';
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

    // Built assets missing → dev mode. Start with: npm run react:dev
    return '<script type="module" src="http://localhost:5173/@vite/client"></script>' . "\n"
         . '<script type="module" src="http://localhost:5173/src/' . rawurlencode($entry) . '.jsx"></script>';
}
