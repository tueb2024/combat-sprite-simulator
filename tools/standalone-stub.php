<?php
// A JumPHP game as one executable (bin/jumphp export:standalone): StaticPHP's micro (PHP with SDL, OpenGL, OpenAL)
// followed by this PHAR. SDL reads pictures and sounds from real files: the game is unpacked once, in the player's
// cache, in a folder of its own version; the saves and options go to the player's data folder (JUMPHP_VAR).
// JUMPHP_STANDALONE_DIR: another folder to unpack into.
Phar::mapPhar('jumphp-game.phar');

const JUMPHP_GAME_ID = '{{ID}}';
const JUMPHP_GAME_BUILD = '{{BUILD}}';

ini_set('memory_limit', '1G');
$windows = PHP_OS_FAMILY === 'Windows';
$mac = PHP_OS_FAMILY === 'Darwin';
$home = (string)(getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir());
$cache = getenv('JUMPHP_STANDALONE_DIR') ?: match (true) {
    $windows => (getenv('LOCALAPPDATA') ?: sys_get_temp_dir()) . '\\JumPHP',
    $mac => "$home/Library/Caches/JumPHP",
    default => (getenv('XDG_CACHE_HOME') ?: "$home/.cache") . '/jumphp',
};
$data = match (true) {
    $windows => (getenv('APPDATA') ?: $home) . '\\' . JUMPHP_GAME_ID,
    $mac => "$home/Library/Application Support/" . JUMPHP_GAME_ID,
    default => (getenv('XDG_DATA_HOME') ?: "$home/.local/share") . '/' . JUMPHP_GAME_ID,
};
$root = $cache . DIRECTORY_SEPARATOR . JUMPHP_GAME_ID . '-' . JUMPHP_GAME_BUILD;

if (!is_file("$root/.unpacked")) {
    $tmp = "$root.tmp" . getmypid();
    $source = 'phar://jumphp-game.phar/game';
    @mkdir($tmp, 0777, true);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($files as $file) {
        $to = $tmp . substr($file->getPathname(), strlen($source));
        if ($file->isDir()) {
            @mkdir($to, 0777, true);
        } elseif (!@copy($file->getPathname(), $to)) {
            fwrite(STDERR, "Cannot unpack the game into $tmp\n");
            exit(1);
        }
    }
    touch("$tmp/.unpacked");
    // another copy of the game unpacked it meanwhile: keep that one
    if (!@rename($tmp, $root)) {
        $rm = static function (string $dir) use (&$rm): void {
            foreach (scandir($dir) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    is_dir("$dir/$name") && !is_link("$dir/$name") ? $rm("$dir/$name") : @unlink("$dir/$name");
                }
            }
            @rmdir($dir);
        };
        $rm($tmp);
    }
}

@mkdir($data, 0777, true);
// Windows: a GUI program (tools/standalone.sh), no console to show PHP's errors: they go to <data>\error.log
if ($windows) {
    ini_set('log_errors', '1');
    ini_set('error_log', $data . '\\error.log');
}
if (getenv('JUMPHP_VAR') === false) {
    putenv("JUMPHP_VAR=$data");
}
// HTTPS: the computer's CA certificates (Debian / Ubuntu / Arch, Fedora / openSUSE), else the game's (Windows, the
// others): PHP's OpenSSL is built in, it does not know where this system keeps them
if (getenv('SSL_CERT_FILE') === false) {
    $system = $windows ? [] : ['/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/cert.pem'];
    foreach ([...$system, "$root/cacert.pem"] as $ca) {
        if (is_file($ca)) {
            putenv("SSL_CERT_FILE=$ca");
            break;
        }
    }
}
// the executable itself (desktop apps' updates replace it: JumPHP\Tauri\Updater); Windows: the one an update moved aside
define('JUMPHP_EXECUTABLE', (string)realpath(__FILE__));   // micro runs the executable itself as this script
if ($windows && is_file(JUMPHP_EXECUTABLE . '.old')) {
    @unlink(JUMPHP_EXECUTABLE . '.old');
}
// the folder the player started it from (paths typed on its command line are relative to it)
define('JUMPHP_CWD', (string)getcwd());
// a code update of the app (JumPHP\Tauri\Overlay, docs/tauri.md "Code updates"): run from the unpacked app with the signed overlay on top;
// any trouble, or an overlay that did not start last time, means the executable's own files. Older apps have no such file: nothing happens.
if (is_file("$root/vendor/jumphp/engine/src/Tauri/Overlay.php")) {
    require_once "$root/vendor/jumphp/engine/src/Tauri/Overlay.php";
    $root = \JumPHP\Tauri\Overlay::resolve($root, (string)(getenv('JUMPHP_VAR') ?: $data));
}
chdir($root);
require "$root/vendor/autoload.php";
// what starts it: the game (JumPHP\Kernel\Application), or a desktop app (JumPHP\Tauri\App, docs/tauri.md)
$boot = '{{BOOT}}';
exit($boot::boot($root)->run());
__HALT_COMPILER();
