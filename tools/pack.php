<?php

// Packs a folder into one file for the browser (bin/jumphp runtime:build: jumphp-vendor.pack, unpacked by
// engine/web/jumphp-files.js): "JPK1", the length of a JSON header (uint32, little endian), the header
// {"files": [[path, offset, size], ...]} (offsets from the end of the header), then the bytes of the files.
//   php tools/pack.php <dir> <out.pack>
[, $dir, $out] = $argv + [null, null, null];
if (!$dir || !$out || !is_dir($dir)) {
    fwrite(STDERR, "usage: php tools/pack.php <dir> <out.pack>\n");
    exit(1);
}
$dir = rtrim($dir, '/');
$paths = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile()) {
        $paths[] = substr($file->getPathname(), strlen($dir) + 1);
    }
}
sort($paths);
$files = [];
$data = '';
foreach ($paths as $path) {
    $bytes = (string)file_get_contents("$dir/$path");
    $files[] = [$path, strlen($data), strlen($bytes)];
    $data .= $bytes;
}
$header = json_encode(['files' => $files], JSON_UNESCAPED_SLASHES);
file_put_contents($out, 'JPK1' . pack('V', strlen($header)) . $header . $data);
printf("%s: %d files, %.1f MB\n", $out, count($files), filesize($out) / 1048576);
