<?php

// Writes the web page of a game: index.html (from engine/web/index.html), game.json (the manifest the future
// games portal reads), the runtime scripts, icon / thumbnail. Called by tools/stage-web.sh.
[, $game, $out, $webDir, $engineDir] = $argv;
require $game . '/vendor/autoload.php';

$config = JumPHP\Kernel\GameConfig::load($game)->all();
$engineInfo = is_file("$engineDir/engine.json") ? json_decode(file_get_contents("$engineDir/engine.json"), true) : [];
$hash = fn (string $file) => is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : '0';

foreach (glob("$webDir/*.js") as $js) {
    copy($js, "$out/" . basename($js));
}
$icon = 'data:,';
foreach (['icon.png', 'thumbnail.png'] as $image) {
    if (is_file("$game/assets/$image")) {
        copy("$game/assets/$image", "$out/$image");
    }
}
if (is_file("$game/assets/icon.png")) {
    $icon = 'icon.png';
}

$manifest = [
    'id' => $config['id'],
    'title' => $config['title'],
    'version' => (string)$config['version'],
    'description' => $config['description'],
    'width' => (int)$config['width'],
    'height' => (int)$config['height'],
    'online' => (bool)$config['online'],
    'lang' => $config['lang'],
    'net_url' => $config['net_url'],
    'touch' => $config['touch'] ?? null,
    'view' => $config['view'] ?? 'fixed',
    'kind' => $config['kind'] ?? (($config['ui'] ?? null) === 'webview' ? 'app' : 'game'),
    'double_click_fullscreen' => (bool)($config['double_click_fullscreen'] ?? false),
    'thumbnail' => is_file("$game/assets/thumbnail.png") ? 'thumbnail.png' : null,
    'controls' => $config['controls'] ?? null,
    'engine' => ['name' => 'JumPHP', 'php' => $engineInfo['php'] ?? '8.3', 'network' => $engineInfo['network'] ?? null],
    'built' => gmdate('c'),
];
if (($config['ui'] ?? null) === 'webview') {
    // a desktop app in the browser (docs/tauri.md "In the browser"): its pages in app/, each with the bridge first
    $manifest['ui'] = 'webview';
    $pages = $game . '/' . trim((string)($config['app']['pages'] ?? 'ui'), '/');
    if (is_dir($pages)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pages, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $rel = substr($file->getPathname(), strlen($pages) + 1);
            $to = "$out/app/$rel";
            @mkdir(dirname($to), 0777, true);
            $body = (string)file_get_contents($file->getPathname());
            if (preg_match('/\.html?$/i', $rel)) {
                $up = str_repeat('../', substr_count($rel, '/') + 1);
                $tag = '<script src="' . $up . 'jumphp-app-frame.js"></script>';
                $body = preg_match('/<head[^>]*>/i', $body) ? (string)preg_replace('/<head[^>]*>/i', "$0\n$tag", $body, 1) : $tag . $body;
            }
            file_put_contents($to, $body);
        }
    }
}
if (is_array($config['pwa'] ?? false) || ($config['pwa'] ?? false) === true) {
    $manifest['pwa'] = true;
}
file_put_contents("$out/game.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$h = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);

// PWA (game.yaml `pwa: true` or {name, short_name, display, orientation, theme_color, background_color}):
// manifest.webmanifest, icons 192 / 512 (assets/icon.png scaled in big pixels, else the title's initial),
// sw.js (the offline cache; tools/package-web.sh writes its build id)
$pwaHead = '';
$pwa = $config['pwa'] ?? false;
if ($pwa === true) {
    $pwa = [];
}
if (is_array($pwa)) {
    $hex = fn (array|string $c) => is_string($c) ? $c : sprintf('#%02x%02x%02x', ...array_slice(array_map('intval', $c), 0, 3));
    $background = $hex($pwa['background_color'] ?? $config['background'] ?? [0, 0, 0]);
    $theme = $hex($pwa['theme_color'] ?? '#000000');
    foreach ([192, 512] as $size) {
        imagepng(pwaIcon($game, $config, $size, $background), "$out/icon-$size.png");
    }
    $orientation = $pwa['orientation'] ?? ((int)$config['width'] >= (int)$config['height'] ? 'landscape' : 'portrait');
    $webManifest = [
        'id' => './',
        'name' => $pwa['name'] ?? $config['title'],
        'short_name' => $pwa['short_name'] ?? mb_substr((string)$config['title'], 0, 12),
        'description' => $config['description'],
        'start_url' => './',
        'scope' => './',
        'display' => $pwa['display'] ?? 'fullscreen',
        'orientation' => $orientation,
        'background_color' => $background,
        'theme_color' => $theme,
        'icons' => [
            ['src' => 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
        'categories' => ['games'],
    ];
    file_put_contents("$out/manifest.webmanifest", json_encode($webManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents("$out/sw.js", str_replace('__JUMPHP_GAME__', preg_replace('/[^a-z0-9-]/', '', (string)$config['id']), file_get_contents("$webDir/sw.js")));
    $pwaHead = '<link rel="manifest" href="manifest.webmanifest" />' . "\n\t" . '<link rel="apple-touch-icon" href="icon-192.png" />'
        . "\n\t" . '<meta name="apple-mobile-web-app-status-bar-style" content="black" />';
    if ($icon === 'data:,') {
        $icon = 'icon-192.png';
    }
} else {
    @unlink("$out/sw.js");
}

/** A square icon: assets/icon.png scaled up (nearest) on the background, else the title's first letter. */
function pwaIcon(string $game, array $config, int $size, string $background): GdImage
{
    $image = imagecreatetruecolor($size, $size);
    [$r, $g, $b] = sscanf(strlen($background) === 4 ? '#' . str_repeat($background[1], 2) . str_repeat($background[2], 2) . str_repeat($background[3], 2) : $background, '#%02x%02x%02x');
    imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
    $source = is_file("$game/assets/icon.png") ? @imagecreatefrompng("$game/assets/icon.png") : false;
    if ($source === false) {
        // the initial in GD's built-in font (5 x 8), scaled up: a pixel-art letter
        $letter = imagecreatetruecolor(8, 10);
        imagefill($letter, 0, 0, imagecolorallocate($letter, $r, $g, $b));
        imagestring($letter, 1, 2, 1, strtoupper(substr(trim((string)$config['title']), 0, 1)) ?: 'J', imagecolorallocate($letter, 255, 210, 63));
        $source = $letter;
    }
    // inside the safe zone of maskable icons (80 %), whole scale factors
    $sw = imagesx($source);
    $sh = imagesy($source);
    $scale = max(1, (int)floor($size * 0.7 / max($sw, $sh)));
    imagecopyresized($image, $source, intdiv($size - $sw * $scale, 2), intdiv($size - $sh * $scale, 2), 0, 0, $sw * $scale, $sh * $scale, $sw, $sh);
    return $image;
}
$html = strtr(file_get_contents("$webDir/index.html"), [
    '{{TITLE}}' => $h($config['title']),
    '{{DESCRIPTION}}' => $h($config['description']),
    '{{LANG}}' => $h($config['lang']),
    '{{WIDTH}}' => (string)(int)$config['width'],
    '{{HEIGHT}}' => (string)(int)$config['height'],
    '{{ICON}}' => $icon,
    '{{PWA_HEAD}}' => $pwaHead,
    '{{ENGINE}}' => '',
    '{{ENGINE_VERSION}}' => $h($engineInfo['php'] ?? ''),
    '{{WASM_SIZE}}' => (string)(is_file("$engineDir/php-web.wasm") ? filesize("$engineDir/php-web.wasm") : 0),
    '{{GAME_JSON}}' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
    '{{V_JS}}' => $hash("$out/jumphp.js"),
    '{{V_TOUCH}}' => $hash("$out/touch.js"),
    '{{V_NET}}' => $hash("$engineDir/php-wasm-network.js"),
]);
file_put_contents("$out/index.html", $html);
echo "web page: $out/index.html\n";
