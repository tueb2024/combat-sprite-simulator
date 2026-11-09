<?php
// The installer of a game or a desktop app (bin/jumphp installer): a jumphp2installer package (install.yaml, the
// executables, the icon) and its installers (Name-setup.exe, Name-setup-linux-x86_64, Name-setup-macos.zip), made
// from the base installers with the package's address written in. Into games/<id>/dist/installer/:
//   --target github  the files of a GitHub release: the installers read the repository's newest release, so they
//                    always install the newest version (bin/jumphp uploads them with gh)
//   --target dir     a folder to put at --url on any web host (the installers read install.yaml there)
//   --target standalone  one installer per system with the game inside it (Binary::embedFile()): installs with no
//                    network and no site, never updates (run with php -d phar.readonly=0)
// Plus installer.json: what was made and the links to give. Run from the repository's root, in Docker.
//
//   php tools/game-installer.php <games/id> <bases folder> --target github --repo owner/repo [--version 1.0]
//       [--platforms windows,linux,macos] [--publisher Name]
//   php tools/game-installer.php <games/id> <bases folder> --target dir --url https://example.com/game/ ...
require __DIR__ . '/installer/Binary.php';
require __DIR__ . '/installer/Zip.php';

use JumPHP2Installer\Binary;
use Symfony\Component\Yaml\Yaml;

$fail = static function (string $message): never {
    fwrite(STDERR, "error: $message\n");
    exit(1);
};
$dir = rtrim((string)($argv[1] ?? ''), '/');
$bases = rtrim((string)($argv[2] ?? ''), '/');
if ($dir === '' || $bases === '' || !is_dir($dir)) {
    $fail('usage: php tools/game-installer.php <games/id> <bases folder> --target github|dir [--repo owner/repo] [--url https://...] [--version X] [--platforms windows,linux,macos] [--publisher Name]');
}
$opts = ['target' => 'dir', 'repo' => '', 'forge' => '', 'url' => '', 'version' => '', 'platforms' => 'windows,linux,macos', 'publisher' => '', 'autostart' => '0', 'stop' => '0'];
for ($i = 3; $i < count($argv); $i++) {
    $key = ltrim($argv[$i], '-');
    array_key_exists($key, $opts) || $fail("unknown option {$argv[$i]}");
    $opts[$key] = (string)($argv[++$i] ?? '');
}
is_file("$dir/vendor/autoload.php") || $fail("$dir has no vendor/ (bin/jumphp install)");
require "$dir/vendor/autoload.php";
// a CLI app has no symfony/yaml of its own: the engine's (bin/jumphp installer installs engine/vendor)
if (!class_exists(Yaml::class) && is_file(__DIR__ . '/../engine/vendor/autoload.php')) {
    require __DIR__ . '/../engine/vendor/autoload.php';
}
class_exists(Yaml::class) || $fail('symfony/yaml is missing (bin/jumphp php engine composer install)');

$config = Yaml::parseFile(is_file("$dir/config/project.yaml") ? "$dir/config/project.yaml" : "$dir/config/game.yaml");
$config = $config['project'] ?? $config['game'] ?? [];
$id = (string)($config['id'] ?? basename($dir));
$title = trim((string)($config['title'] ?? $id)) ?: $id;
// a CLI app (kind: cli): a console program, no shortcut; macOS: its two executables (no .app), the one of the Mac installed
$cli = ($config['kind'] ?? '') === 'cli';
$version = $opts['version'] !== '' ? $opts['version'] : (string)($config['version'] ?? '1.0');
$publisher = $opts['publisher'] !== '' ? $opts['publisher'] : $title;

if ($opts['target'] === 'github') {
    preg_match('#^[\w.-]+/[\w.-]+$#', $opts['repo']) || $fail('--repo owner/repo');
    if ($opts['forge'] !== '') {
        // a forge (Forgejo, git.jumphp.net): the installers know `github:owner/repo` for github.com only, so the package is a static folder: the newest
        // release's files through the forge's alias /releases/download/latest/ (install.yaml and version.json are release files too)
        preg_match('#^https://[^\s/]+$#', $opts['forge']) || $fail('--forge https://host');
        $base = $source = rtrim($opts['forge'], '/') . '/' . $opts['repo'] . '/releases/download/latest/';
        $versionRule = ['latest' => $base . 'version.json'];
    } else {
        $source = 'github:' . $opts['repo'];
        $base = 'https://github.com/' . $opts['repo'] . '/releases/latest/download/';
        $versionRule = ['github' => $opts['repo']];
    }
} elseif ($opts['target'] === 'standalone') {
    $source = $base = '';
    $versionRule = null;
    filter_var(ini_get('phar.readonly'), FILTER_VALIDATE_BOOL) && $fail('--target standalone needs php -d phar.readonly=0');
} elseif ($opts['target'] === 'dir') {
    preg_match('#^https?://[^\s]+$#', $opts['url']) || $fail('--url: the https:// address where the folder will be put');
    $base = $source = rtrim($opts['url'], '/') . '/';
    $versionRule = ['latest' => $base . 'version.json'];
} else {
    $fail('--target github or dir');
}

// the newest build of each system the game has (the same places as the IDE's jumphp.net window)
$wanted = array_filter(array_map('trim', explode(',', $opts['platforms'])));
$found = [];
foreach (['windows' => ['standalone/*-windows-x86_64.exe'], 'linux' => ['standalone/*-linux-x86_64'],
    'macos' => ['apple/*-macos.zip', 'standalone/*-macos.zip']] as $platform => $patterns) {
    if (!in_array($platform, $wanted, true)) {
        continue;
    }
    if ($platform === 'macos' && $cli) {
        $arm = glob("$dir/dist/standalone/*-macos-arm64") ?: [];
        $intel = glob("$dir/dist/standalone/*-macos-x86_64") ?: [];
        ($arm === [] || $intel === []) && $fail("no macOS build in $dir/dist/standalone/ (bin/jumphp export:standalone $id --macos)");
        $found['macos'] = ['arm64' => $arm[0], 'x86_64' => $intel[0]];
        continue;
    }
    $files = array_merge(...array_map(static fn (string $g) => glob("$dir/dist/$g") ?: [], $patterns));
    usort($files, static fn (string $a, string $b) => filemtime($b) <=> filemtime($a));
    $files === [] && $fail("no $platform build in $dir/dist/ (bin/jumphp installer builds Windows and Linux itself; macOS: bin/jumphp macos $id, or app:export --macos for an app)");
    $found[$platform] = $files[0];
}
$found !== [] || $fail('no system chosen (--platforms windows,linux,macos)');

$out = "$dir/dist/installer";
if (is_dir($out)) {
    foreach (glob("$out/*") ?: [] as $old) {
        is_file($old) && unlink($old);
    }
}
@mkdir($out, 0777, true);
$standalone = $opts['target'] === 'standalone';
// standalone: the package is made in a folder per system, then put inside its installer (only the installers stay)
$package = $standalone ? sys_get_temp_dir() . "/jumphp-installer-$id-" . getmypid() : $out;
@mkdir($package, 0777, true);
$copy = static fn (string $from, string $name) => copy($from, "$package/$name") || $fail("cannot write $package/$name");

// the package: the executables under fixed names (installed games take the newest one each time)
$names = ['windows' => "$id-windows-x86_64.exe", 'linux' => "$id-linux-x86_64", 'macos' => $cli ? ["$id-macos-arm64", "$id-macos-x86_64"] : "$id-macos.zip"];
$files = [];
foreach ($found as $platform => $file) {
    foreach ((array)$names[$platform] as $i => $name) {
        $copy(is_array($file) ? array_values($file)[$i] : $file, $name);
        $files[] = $name;
    }
}
$icon = null;
foreach (['assets/icon.png', 'assets/thumbnail.png'] as $candidate) {
    if (is_file("$dir/$candidate")) {
        $copy("$dir/$candidate", $icon = 'icon.png');
        $files[] = 'icon.png';
        break;
    }
}

// a desktop app with project.yaml app.autostart: a "start with the computer" box (bin/jumphp gives --autostart 1 when the base installers know the step: 0.2.9+)
$appConfig = (array)($config['app'] ?? []);
$autostart = $appConfig['autostart'] ?? false;
$autostartDefault = is_array($autostart) ? (bool)($autostart['default'] ?? false) : (bool)$autostart;
$stop = $opts['stop'] === '1';
$autostart = !$cli && $autostart !== false && $autostart !== null && $opts['autostart'] === '1';
$identifier = (string)($appConfig['identifier'] ?? 'net.jumphp.' . preg_replace('/[^a-z0-9]+/', '', strtolower($id)));

// the script: what the playground writes for a game, with the package's own files
$scriptOf = static function (array $found, ?array $versionRule) use ($id, $title, $publisher, $names, $version, $cli, &$icon, $autostart, $autostartDefault, $identifier, $stop): array {
    $desktop = array_values(array_intersect(['windows', 'linux'], array_keys($found)));
    $steps = [];
    // an update of an app that is open: quit it first, its files are in use (jumphp2installer 0.2.10; also stopped by the uninstaller)
    $stop && $steps[] = ['stop' => ['path' => '{folder}'], 'os' => array_keys($found)];
    $run = [];
    foreach ($desktop as $os) {
        $steps[] = ['download' => $names[$os], 'os' => $os];
        $run[$os] = "{folder}/$id" . ($os === 'windows' ? '.exe' : '');
    }
    if ($desktop !== []) {
        $steps[] = ['copy' => ['from' => '{download}', 'to' => "{folder}/$id{exe}"], 'os' => $desktop];
        if (in_array('linux', $desktop, true)) {
            $steps[] = ['executable' => "{folder}/$id", 'os' => 'linux'];
        }
        if (!$cli) {
            $steps[] = ['shortcut' => ['target' => "{folder}/$id{exe}", 'where' => 'both'], 'if' => 'desktop', 'os' => $desktop];
        }
    }
    if (isset($found['macos']) && $cli) {
        // the executable of this Mac's processor ({arch}: arm64 or x86_64)
        $steps[] = ['download' => "$id-macos-{arch}", 'os' => 'macos'];
        $steps[] = ['copy' => ['from' => '{download}', 'to' => "{folder}/$id"], 'os' => 'macos'];
        $steps[] = ['executable' => "{folder}/$id", 'os' => 'macos'];
    }
    if ($cli) {
        $steps[] = ['command' => "{folder}/$id{exe}"];     // typed by its name in a terminal (jumphp2installer 0.2.8)
    } elseif (isset($found['macos'])) {
        $steps[] = ['download' => $names['macos'], 'os' => 'macos'];
        $steps[] = ['extract' => '{folder}', 'os' => 'macos'];
    }
    if ($autostart) {
        // the app's own first start leaves the choice alone when this file is beside it (JumPHP\Tauri\App)
        $steps[] = ['write' => ['file' => "{folder}/.jumphp-autostart", 'text' => "the installer asked\n"], 'os' => array_keys($found)];
        foreach (array_keys($found) as $os) {
            $target = $os === 'macos' ? '{folder}' : "{folder}/$id" . ($os === 'windows' ? '.exe' : '');
            $steps[] = ['autostart' => ['target' => $target, 'id' => $id, 'identifier' => $identifier, 'name' => '{app}'], 'if' => 'autostart', 'os' => $os];
        }
    }
    $steps[] = 'uninstaller';
    $options = ['desktop' => 'A shortcut on the desktop'] + ($autostart ? ['autostart' => ['label' => "Start $title with the computer", 'default' => $autostartDefault]] : []);
    return [
        'installer' => 1,
        'app' => ['name' => $title, 'publisher' => $publisher] + ($icon !== null ? ['icon' => $icon] : []),
        'version' => $versionRule ?? $version,
        'pages' => $cli ? ['welcome', ['folder' => '{programs}/{app}'], 'install', 'finish']
            : ['welcome', ['folder' => '{programs}/{app}'], ['options' => $options], 'install',
                $run !== [] ? ['finish' => ['run' => $run]] : 'finish'],
        'steps' => $steps,
    ];
};
if (!$standalone) {
    $script = $scriptOf($found, $versionRule);
    file_put_contents("$out/install.yaml", "# The installer of $id (bin/jumphp installer): it installs the newest version put at its address.\n" . Yaml::dump($script, 4, 2));
    $files[] = 'install.yaml';
}
if ($opts['target'] === 'dir' || ($opts['target'] === 'github' && $opts['forge'] !== '')) {
    file_put_contents("$out/version.json", json_encode(['version' => $version]) . "\n");
    $files[] = 'version.json';
}

// the installers: the base ones with the package's address written in
$name = trim((string)preg_replace('/[^\w.-]+/', '-', $title), '-') ?: 'Setup';
$setups = [];
foreach (['windows' => ['jumphp2installer-windows-x86_64.exe', "$name-setup.exe"],
    'linux' => ['jumphp2installer-linux-x86_64', "$name-setup-linux-x86_64"],
    'macos' => ['jumphp2installer-macos.zip', "$name-setup-macos.zip"]] as $platform => [$from, $to]) {
    if (!isset($found[$platform])) {
        continue;
    }
    is_file("$bases/$from") || $fail("no base installer $bases/$from");
    try {
        if ($standalone) {
            // this system's package alone: its executable, the icon, a script of this version
            $one = "$package/$platform";
            @mkdir($one, 0777, true);
            foreach ((array)$names[$platform] as $file) {
                rename("$package/$file", "$one/$file");
            }
            $icon !== null && copy("$package/$icon", "$one/$icon");
            file_put_contents("$one/install.yaml", "# The standalone installer of $id $version (bin/jumphp installer --standalone).\n"
                . Yaml::dump($scriptOf([$platform => $found[$platform]], null), 4, 2));
            Binary::embedFile("$bases/$from", "$out/$to", $one);
        } else {
            Binary::patchFile("$bases/$from", "$out/$to", $source, 'static');
        }
    } catch (Throwable $e) {
        $fail("$from: " . $e->getMessage());
    }
    $setups[$platform] = $to;
}
if ($standalone) {
    $rm = static function (string $path) use (&$rm): void {
        if (is_dir($path)) {
            array_map($rm, glob("$path/*") ?: []);
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $rm($package);
    $files = [];
}

$result = [
    'target' => $opts['target'], 'source' => $source, 'version' => $version, 'folder' => $out,
    'files' => [...$files, ...array_values($setups)],
    'installers' => array_map(static fn (string $f) => ['file' => $f] + ($standalone ? [] : ['url' => $base . $f]), $setups),
];
file_put_contents("$out/installer.json", json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
foreach ($result['installers'] as $platform => $i) {
    echo "$platform: {$i['file']}\n";
}
