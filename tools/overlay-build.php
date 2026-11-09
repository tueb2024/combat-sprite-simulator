<?php
// The code update of a desktop app (JumPHP\Tauri\Overlay): the archive of the files that change, and update.json signed again with it.
//   php tools/overlay-build.php <repo root> <app dir> <key file> <update.json> <out dir> <overlay url base> [notes]
// <app dir> is games/ide: its src/ and config/ and the IDE package's src/ and ui/ (without ui/vendor) make the archive. update.json (the
// last full release's, already signed) keeps everything it says and gains `overlay`; it is signed again with the same key.
// Writes <out dir>/ide-code-<version>.tar.gz and <out dir>/update.json.

[, $repo, $dir, $keyFile, $updateJson, $out, $base, $notes] = $argv + array_fill(0, 8, '');
$fail = static function (string $message): never {
    fwrite(STDERR, "$message\n");
    exit(1);
};
$base === '' && $fail('usage: overlay-build.php <repo root> <app dir> <key file> <update.json> <out dir> <url base> [notes]');
is_file($keyFile) || $fail("no key: $keyFile");

$yaml = (string)file_get_contents("$dir/config/game.yaml");
$value = static fn (string $key): string => preg_match('/^\s*' . preg_quote($key, '/') . ':\s*["\']?([^"\'#\n]*?)["\']?\s*(#.*)?$/m', $yaml, $m) ? $m[1] : '';
$id = $value('id');
$version = $value('version');
$compat = (int)$value('overlay_compat');
($id !== '' && $version !== '' && $compat > 0) || $fail('config/game.yaml needs id, version and overlay_compat');

// where each file comes from, and where it goes in the unpacked app
$sources = [
    ['from' => "$dir/src", 'to' => 'src', 'skip' => []],
    ['from' => "$dir/config", 'to' => 'config', 'skip' => []],
    ['from' => "$repo/ide/src", 'to' => 'vendor/jumphp/ide/src', 'skip' => []],
    ['from' => "$repo/ide/ui", 'to' => 'vendor/jumphp/ide/ui', 'skip' => ['vendor/']],
];
$files = [];
foreach ($sources as $source) {
    is_dir($source['from']) || $fail("missing {$source['from']}");
    $length = strlen(rtrim($source['from'], '/')) + 1;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source['from'], FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), $length);
        foreach ($source['skip'] as $skip) {
            if (str_starts_with($relative, $skip)) {
                continue 2;
            }
        }
        if ($relative === 'update.pub' && $source['to'] === 'config') {
            continue;       // the key that checks the updates is never replaced by an update
        }
        if (str_ends_with($relative, '.md') || str_contains($relative, '/tests/')) {
            continue;       // what stage-game.sh leaves out of the app
        }
        $files[$source['to'] . '/' . $relative] = $file->getPathname();
    }
}
ksort($files);

@mkdir($out, 0777, true);
$tar = "$out/ide-code-$version.tar";
@unlink($tar);
@unlink("$tar.gz");
$phar = new PharData($tar);
$hashes = array_map(fn (string $path) => hash_file('sha256', $path), $files);
$phar->addFromString('manifest.json', json_encode(['version' => $version, 'compat' => $compat, 'delete' => [], 'files' => $hashes], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
foreach ($files as $to => $from) {
    $phar->addFile($from, "files/$to");
}
$phar->compress(Phar::GZ);
unset($phar);
@unlink($tar);
$archive = "$tar.gz";
$bytes = (string)file_get_contents($archive);

// update.json: what the last full release said, plus the overlay, signed again
$current = json_decode((string)file_get_contents($updateJson), true);
$signed = is_array($current) ? json_decode((string)($current['signed'] ?? ''), true) : null;
is_array($signed) || $fail("$updateJson is not a signed update.json");
($signed['id'] ?? null) === $id || $fail('update.json is for another app');
version_compare($version, (string)$signed['version'], '>') || $fail("the version $version is not newer than the full release " . $signed['version'] . ' (raise version in game.yaml)');
$signed['overlay'] = [
    'version' => $version, 'compat' => $compat, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
    'url' => rtrim($base, '/') . '/' . rawurlencode(basename($archive)), 'notes' => $notes,
];
$signed['date'] = gmdate('Y-m-d\TH:i:s\Z');
$json = json_encode($signed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$key = openssl_pkey_get_private((string)file_get_contents($keyFile));
$key !== false || $fail('cannot read the key');
openssl_sign($json, $signature, $key, OPENSSL_ALGO_SHA256) || $fail('openssl: cannot sign');
file_put_contents("$out/update.json", json_encode(['signed' => $json, 'signature' => base64_encode($signature)], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
echo "OUT=$archive\nVERSION=$version\nFILES=" . count($files) . "\nSIZE=" . strlen($bytes) . "\n";
