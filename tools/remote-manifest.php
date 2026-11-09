<?php

// A game as a "remote" game (bin/jumphp build:remote, docs/remote.md): its files one by one in <out>/files/,
// <out>/manifest.json (what jumphp-files.js loads) and <out>/index.html (engine/web/index.html with the
// shared engine folder). A games site does the same with its own code: the format is in docs/remote.md.
//   php tools/remote-manifest.php <game dir> <out dir> [engine url = engine/] [runtime dir = build/runtime]
[, $game, $out, $engineUrl, $runtime] = $argv + [null, null, null, 'engine/', __DIR__ . '/../build/runtime'];
if (!$game || !$out || !is_file("$game/config/game.yaml") && !is_file("$game/config/project.yaml")) {
    fwrite(STDERR, "usage: php tools/remote-manifest.php <game dir> <out dir> [engine url] [runtime dir]\n");
    exit(1);
}
if (!is_file("$runtime/version.json")) {
    fwrite(STDERR, "no runtime in $runtime: bin/jumphp runtime:build\n");
    exit(1);
}
require "$game/vendor/autoload.php";

// what a normal build leaves out too (tools/stage-web.sh)
const SKIP = '#^(vendor|dist|\.build|var|cache|tests|site|\.git[^/]*)(/|$)|^phpstan\.neon$|(^|/)core(\.[0-9]+)?$|(^|/)\.DS_Store$|^assets/videos/[^/]+\.(mp4|webm|mov|mkv|avi|m4v)$#i';

$config = JumPHP\Kernel\GameConfig::load($game)->all();
$composer = json_decode((string)@file_get_contents("$game/composer.json"), true) ?: [];
$autoload = [];
foreach ($composer['autoload']['psr-4'] ?? ['App\\' => 'src/'] as $prefix => $dirs) {
    $autoload[$prefix] = array_values((array)$dirs);
}

$game = rtrim($game, '/');
@mkdir("$out/files", 0777, true);
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($game, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = substr($file->getPathname(), strlen($game) + 1);
    if (!$file->isFile() || preg_match(SKIP, $path)) {
        continue;
    }
    @mkdir(dirname("$out/files/$path"), 0777, true);
    copy($file->getPathname(), "$out/files/$path");
    $files[] = ['path' => $path, 'hash' => substr(hash_file('sha256', $file->getPathname()), 0, 16), 'size' => $file->getSize()];
}

// the game's engine extensions (Composer type jumphp-extension) and the packages they need that the shared engine has
// not: copied under vendor-extensions/ with a Composer-like installed.json (JumPHP\Extension\Extensions finds them)
$installed = json_decode((string)@file_get_contents("$game/vendor/composer/installed.json"), true) ?: [];
$installed = array_column($installed['packages'] ?? $installed, null, 'name');
$shared = runtimePackages("$runtime/jumphp-vendor.pack");
$wanted = [];
$add = function (string $name) use (&$add, &$wanted, $installed, $shared): void {
    if (isset($wanted[$name]) || isset($shared[$name]) || $name === 'jumphp/engine' || !isset($installed[$name])) {
        return;
    }
    $wanted[$name] = $installed[$name];
    foreach (array_keys($installed[$name]['require'] ?? []) as $dep) {
        $add((string)$dep);
    }
};
foreach ($installed as $name => $p) {
    if (($p['type'] ?? '') === 'jumphp-extension') {
        $add((string)$name);
    }
}
$extensions = [];
foreach ($wanted as $name => $p) {
    $dir = realpath("$game/vendor/composer/" . ($p['install-path'] ?? "../$name"));
    if ($dir === false) {
        fwrite(STDERR, "$name: not installed (bin/jumphp install)\n");
        exit(1);
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS));
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($dir) + 1);
        if (!$file->isFile() || preg_match('#^(vendor|tests|\.git[^/]*)(/|$)#', $rel)) {
            continue;
        }
        $path = "vendor-extensions/$name/$rel";
        @mkdir(dirname("$out/files/$path"), 0777, true);
        copy($file->getPathname(), "$out/files/$path");
        $files[] = ['path' => $path, 'hash' => substr(hash_file('sha256', $file->getPathname()), 0, 16), 'size' => $file->getSize()];
    }
    foreach ((array)($p['autoload']['psr-4'] ?? []) as $prefix => $dirs) {
        foreach ((array)$dirs as $d) {
            $autoload[$prefix][] = rtrim("vendor-extensions/$name/" . trim((string)$d, '/'), '/') . '/';
        }
    }
    $extensions[] = ['name' => $name, 'type' => $p['type'] ?? 'library', 'version' => $p['version'] ?? 'dev',
        'extra' => $p['extra'] ?? new stdClass(), 'install-path' => $name];
}
if ($extensions) {
    $json = json_encode(['packages' => $extensions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    @mkdir("$out/files/vendor-extensions", 0777, true);
    file_put_contents("$out/files/vendor-extensions/installed.json", $json);
    $files[] = ['path' => 'vendor-extensions/installed.json', 'hash' => substr(hash('sha256', $json), 0, 16), 'size' => strlen($json)];
}
usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

$version = json_decode((string)file_get_contents("$runtime/version.json"), true);
$runtimeInfo = json_decode((string)@file_get_contents("$runtime/runtime.json"), true) ?: [];
$info = [
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
    'controls' => $config['controls'] ?? null,
];
$manifest = ['format' => 1, 'engine' => $runtimeInfo['version'] ?? null] + $info
    + ['base' => 'files/', 'autoload' => $autoload, 'files' => $files];
file_put_contents("$out/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$h = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
$html = strtr(file_get_contents("$runtime/page.html"), [
    '{{TITLE}}' => $h($config['title']),
    '{{DESCRIPTION}}' => $h($config['description']),
    '{{LANG}}' => $h($config['lang']),
    '{{WIDTH}}' => (string)(int)$config['width'],
    '{{HEIGHT}}' => (string)(int)$config['height'],
    '{{ICON}}' => is_file("$game/assets/icon.png") ? 'files/assets/icon.png' : 'data:,',
    '{{PWA_HEAD}}' => '',
    '{{ENGINE}}' => $h($engineUrl),
    '{{ENGINE_VERSION}}' => $h($runtimeInfo['php'] ?? ''),
    '{{WASM_SIZE}}' => (string)(int)($runtimeInfo['wasm_size'] ?? filesize("$runtime/php-web.wasm")),
    '{{GAME_JSON}}' => json_encode($info + ['remote' => ['manifest' => 'manifest.json']], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP),
    '{{V_JS}}' => $version['jumphp.js'] ?? '0',
    '{{V_TOUCH}}' => $version['touch.js'] ?? '0',
    '{{V_NET}}' => $version['php-wasm-network.js'] ?? '0',
]);
file_put_contents("$out/index.html", $html);
printf("remote game: %s (%d files)\n", $out, count($files));

/** @return array<string, true> the Composer packages inside the shared engine's jumphp-vendor.pack */
function runtimePackages(string $pack): array
{
    $data = (string)@file_get_contents($pack);
    if (strlen($data) < 8 || substr($data, 0, 4) !== 'JPK1') {
        return [];
    }
    $length = unpack('V', substr($data, 4, 4))[1];
    $header = json_decode(substr($data, 8, $length), true) ?: [];
    foreach ($header['files'] ?? [] as [$path, $offset, $size]) {
        if ($path === 'composer/installed.json') {
            $installed = json_decode(substr($data, 8 + $length + $offset, $size), true) ?: [];
            return array_fill_keys(array_column($installed['packages'] ?? $installed, 'name'), true);
        }
    }
    return [];
}
