<?php
// bin/jumphp extension:add: what an extension brings into the game besides its code (docs/extensions.md "Samples and documentation").
// Run in the game's folder: php tools/extension-adopt.php <vendor/package>
//   vendor/<pkg>/doc/samples/*.php  (older packages: samples/*.php)  ->  src/Extensions/<Name>/   scenes that play and build with the game (namespace App\Extensions\<Name>)
//   vendor/<pkg>/doc/           ->  extensions/<package name>/doc/     (else its README.md as doc/index.md)
//   extensions/<package name>/extension.json   what the IDE reads: the documentation and a Play button per sample
// Nothing of the game's own is overwritten: a sample the game already holds (maybe edited) stays.
[$pkg] = [$argv[1] ?? ''];
if (!preg_match('#^([a-z0-9][a-z0-9_.-]*)/([a-z0-9][a-z0-9_.-]*)$#', $pkg, $m)) {
    fwrite(STDERR, "usage: extension-adopt.php <vendor/package>\n");
    exit(2);
}
[, $vendor, $name] = $m;
$dir = "vendor/$pkg";
$composer = json_decode((string)@file_get_contents("$dir/composer.json"), true);
if (!is_array($composer)) {
    fwrite(STDERR, "[!] $dir/composer.json not found\n");
    exit(1);
}
$extra = (array)($composer['extra']['jumphp'] ?? []);
$pascal = str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $name)));
$copyDir = function (string $from, string $to) use (&$copyDir): int {
    $n = 0;
    @mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        if (is_dir("$from/$f")) {
            $n += $copyDir("$from/$f", "$to/$f");
        } else {
            copy("$from/$f", "$to/$f");
            $n++;
        }
    }
    return $n;
};

// samples: scenes in the game's own namespace, so that they autoload, autowire and go into the browser build with it
$samples = [];
$samplesDir = $dir . '/' . trim((string)($extra['samples'] ?? (is_dir("$dir/doc/samples") ? 'doc/samples' : 'samples')), '/');
if (is_dir($samplesDir)) {
    @mkdir("src/Extensions/$pascal", 0777, true);
    foreach (glob("$samplesDir/*.php") ?: [] as $file) {
        $target = "src/Extensions/$pascal/" . basename($file);
        if (!is_file($target)) {
            $code = (string)file_get_contents($file);
            // the sample belongs to the game: its namespace is the game's (the extension's authors write App\Extensions\<Name>)
            $code = (string)preg_replace('/^namespace\s+[^;]+;/m', "namespace App\\Extensions\\$pascal;", $code, 1);
            file_put_contents($target, $code);
        }
        $code = (string)file_get_contents($target);
        $class = "App\\Extensions\\$pascal\\" . basename($file, '.php');
        $samples[] = ['class' => $class, 'file' => $target, 'label' => preg_match("/const\s+LABEL\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $l) ? stripslashes($l[1]) : basename($file, '.php')];
    }
}

// documentation
$docDir = $dir . '/' . trim((string)($extra['doc'] ?? 'doc'), '/');
$docTarget = "extensions/$name/doc";
$doc = null;
if (is_dir($docDir)) {
    $copyDir($docDir, $docTarget);
    // the samples are scenes of the game (src/Extensions), not documentation files: no second copy of their code in extensions/
    $rm = function (string $d) use (&$rm): void {
        foreach (scandir($d) ?: [] as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$d/$f") ? $rm("$d/$f") : @unlink("$d/$f");
            }
        }
        @rmdir($d);
    };
    is_dir("$docTarget/samples") && $rm("$docTarget/samples");
    $doc = is_file("$docTarget/index.md") ? 'doc/index.md' : null;
}
if ($doc === null && is_file("$dir/README.md")) {
    @mkdir($docTarget, 0777, true);
    copy("$dir/README.md", "$docTarget/index.md");
    $doc = 'doc/index.md';
}

$installed = json_decode((string)@file_get_contents('vendor/composer/installed.json'), true);
$version = $composer['version'] ?? null;
foreach (is_array($installed) ? ($installed['packages'] ?? $installed) : [] as $p) {
    if (($p['name'] ?? '') === $pkg) {
        $version = $p['version'] ?? $version;
    }
}
@mkdir("extensions/$name", 0777, true);
file_put_contents("extensions/$name/extension.json", json_encode([
    'package' => $pkg, 'name' => (string)($extra['name'] ?? $pascal), 'version' => (string)$version, 'description' => (string)($composer['description'] ?? ''),
    'doc' => $doc, 'samples' => $samples, 'homepage' => (string)($composer['homepage'] ?? ''), 'engine' => (string)($extra['engine'] ?? ''),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo $doc !== null ? "documentation: extensions/$name/$doc\n" : "documentation: none in the package\n";
foreach ($samples as $s) {
    echo "sample: {$s['class']} ({$s['label']})\n";
}
