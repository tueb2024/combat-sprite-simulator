<?php
// bin/jumphp app:release: the signed update.json of an exported desktop app (JumPHP\Tauri\Updater, docs/tauri.md
// "Updates"), next to its executables in dist/standalone/.
//   php tools/app-release.php <app dir> <key file> <base url> [notes] [platforms]
// platforms: those just exported (linux-x86_64,windows-x86_64,macos-arm64,macos-x86_64; default: every file there)
// The key (ECDSA P-256) is made the first time, in <key file> (never in the project: keep a copy of it); its public
// half goes to <app dir>/config/update.pub, which the app carries to check what it downloads.

[, $dir, $keyFile, $base, $notes, $only] = $argv + array_fill(0, 6, '');
$fail = static function (string $message): never {
    fwrite(STDERR, "$message\n");
    exit(1);
};
$base === '' && $fail('usage: app-release.php <app dir> <key file> <base url> [notes]');

$yaml = (string)file_get_contents(is_file("$dir/config/project.yaml") ? "$dir/config/project.yaml" : "$dir/config/game.yaml");
$value = static fn (string $key, string $default): string =>
    preg_match('/^\s*' . preg_quote($key, '/') . ':\s*["\']?([^"\'#\n]*?)["\']?\s*(#.*)?$/m', $yaml, $m) && $m[1] !== '' ? $m[1] : $default;
$id = $value('id', basename($dir));
$version = $value('version', '');
$version === '' && $fail("game.yaml has no version: updates compare versions (game: version: 1.0.0)");

// the key: made once, kept outside the project
if (!is_file($keyFile)) {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) ?: $fail('openssl: no key');
    openssl_pkey_export($key, $pem) || $fail('openssl: cannot export the key');
    @mkdir(dirname($keyFile), 0700, true);
    file_put_contents($keyFile, $pem) || $fail("cannot write $keyFile");
    chmod($keyFile, 0600);
    fwrite(STDERR, "new signing key: $keyFile (keep a copy: without it, the apps already given can not be updated)\n");
}
$key = openssl_pkey_get_private((string)file_get_contents($keyFile)) ?: $fail("$keyFile: not a private key");
$public = (string)(openssl_pkey_get_details($key)['key'] ?? '');
$pubFile = "$dir/config/update.pub";
if (!is_file($pubFile)) {
    file_put_contents($pubFile, $public);
    fwrite(STDERR, "config/update.pub written: commit it (the app checks its updates with it)\n");
} elseif (trim((string)file_get_contents($pubFile)) !== trim($public)) {
    $fail("config/update.pub is not the public half of $keyFile: the app would refuse these updates");
}

// the executables there: each one's address, size and SHA-256
$out = "$dir/dist/standalone";
$files = [
    'linux-x86_64' => "$id-linux-x86_64",
    'windows-x86_64' => "$id-windows-x86_64.exe",
    'macos-arm64' => "$id-macos-arm64",
    'macos-x86_64' => "$id-macos-x86_64",
];
$platforms = [];
$wanted = $only === '' ? array_keys($files) : explode(',', $only);
foreach ($files as $platform => $file) {
    if (in_array($platform, $wanted, true) && is_file("$out/$file")) {
        $platforms[$platform] = [
            'url' => rtrim($base, '/') . '/' . rawurlencode($file),
            'size' => filesize("$out/$file"),
            'sha256' => hash_file('sha256', "$out/$file"),
        ];
    }
}
$platforms === [] && $fail("no executable in $out (bin/jumphp app:export first)");

$signed = json_encode([
    'id' => $id,
    'version' => $version,
    'notes' => $notes,
    'date' => gmdate('Y-m-d\TH:i:s\Z'),
    'platforms' => $platforms,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
openssl_sign($signed, $signature, $key, OPENSSL_ALGO_SHA256) || $fail('openssl: cannot sign');
file_put_contents("$out/update.json", json_encode(['signed' => $signed, 'signature' => base64_encode($signature)],
    JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
echo "OUT=$out/update.json\n";
echo implode(' ', array_keys($platforms)), "\n";
