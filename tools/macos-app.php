<?php
// A desktop app (docs/tauri.md) as a macOS application, from Linux (bin/jumphp app:export --macos), in the desktop
// image (GD, zip): <Title>.app with both executables (macos-arm64, macos-x86_64: micro + the app's PHAR, made by
// tools/standalone.sh), a launcher that picks the Mac's one, Info.plist and the icon; zipped with the Unix
// permissions kept (Finder's Archive Utility restores them).
//   php tools/macos-app.php <app dir> <arm64 executable> <x86_64 executable> <out .zip>
// Not signed (ad hoc at most): the Mac opens it after right click > Open (docs/tauri.md, "macOS").

[, $dir, $arm, $intel, $out] = $argv + array_fill(0, 5, '');
foreach ([$arm, $intel] as $file) {
    is_file($file) || exit(fwrite(STDERR, "no $file\n") ? 1 : 1);
}

$yaml = (string)file_get_contents(is_file("$dir/config/project.yaml") ? "$dir/config/project.yaml" : "$dir/config/game.yaml");
$value = static function (string $key, string $default) use ($yaml): string {
    // the first "<key>: value" of game.yaml (plain values only; quotes removed)
    return preg_match('/^\s*' . preg_quote($key, '/') . ':\s*["\']?([^"\'#\n]*?)["\']?\s*(#.*)?$/m', $yaml, $m) && $m[1] !== ''
        ? $m[1] : $default;
};
$id = $value('id', basename($dir));
$title = $value('title', $id);
$version = $value('version', '1.0.0');
$identifier = $value('identifier', 'net.jumphp.' . preg_replace('/[^a-z0-9]+/', '', strtolower($id)));
$name = trim(preg_replace('/[\/:*?"<>|]+/', ' ', $title)) ?: $id;
$exe = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $id);

$xml = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);
$plist = <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>CFBundleName</key><string>{$xml($name)}</string>
  <key>CFBundleDisplayName</key><string>{$xml($name)}</string>
  <key>CFBundleIdentifier</key><string>{$xml($identifier)}</string>
  <key>CFBundleVersion</key><string>{$xml($version)}</string>
  <key>CFBundleShortVersionString</key><string>{$xml($version)}</string>
  <key>CFBundleExecutable</key><string>{$xml($exe)}</string>
  <key>CFBundleIconFile</key><string>icon</string>
  <key>CFBundlePackageType</key><string>APPL</string>
  <key>CFBundleInfoDictionaryVersion</key><string>6.0</string>
  <key>LSMinimumSystemVersion</key><string>12.0</string>
  <key>NSHighResolutionCapable</key><true/>
  <key>LSApplicationCategoryType</key><string>public.app-category.productivity</string>
</dict>
</plist>

PLIST;

// the launcher: the Mac's own executable (an Apple silicon Mac runs the arm64 one natively)
$launcher = <<<SH
#!/bin/sh
dir="\$(cd "\$(dirname "\$0")" && pwd)"
case "\$(uname -m)" in
  arm64) exec "\$dir/$exe-arm64" "\$@" ;;
  *) exec "\$dir/$exe-x86_64" "\$@" ;;
esac

SH;

// the icon: assets/icon.png (or the Tauri runtime's), as an .icns of PNGs (ic07 128, ic08 256, ic09 512)
$iconFile = is_file("$dir/assets/icon.png") ? "$dir/assets/icon.png" : dirname(__DIR__) . '/tauri/icons/icon.png';
$source = @imagecreatefrompng($iconFile);
$icns = '';
if ($source !== false) {
    foreach (['ic07' => 128, 'ic08' => 256, 'ic09' => 512] as $type => $size) {
        $img = imagecreatetruecolor($size, $size);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        // pixel art stays sharp: nearest neighbour when it grows by a whole factor
        if ($size % imagesx($source) === 0 && imagesx($source) === imagesy($source)) {
            imagecopyresized($img, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
        } else {
            imagecopyresampled($img, $source, 0, 0, 0, 0, $size, $size, imagesx($source), imagesy($source));
        }
        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        $icns .= $type . pack('N', 8 + strlen($png)) . $png;
    }
    $icns = 'icns' . pack('N', 8 + strlen($icns)) . $icns;
}

@unlink($out);
@mkdir(dirname($out), 0777, true);
$zip = new ZipArchive();
$zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true || exit(fwrite(STDERR, "cannot write $out\n") ? 1 : 1);
$app = "$name.app/Contents";
$add = static function (string $path, string $data, int $mode) use ($zip): void {
    $zip->addFromString($path, $data);
    $zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, ($mode | 0100000) << 16);
};
foreach (["$name.app/", "$app/", "$app/MacOS/", "$app/Resources/"] as $folder) {
    $zip->addEmptyDir(rtrim($folder, '/'));
    $zip->setExternalAttributesName($folder, ZipArchive::OPSYS_UNIX, (0755 | 040000) << 16);
}
$add("$app/Info.plist", $plist, 0644);
$add("$app/PkgInfo", "APPL????", 0644);
$add("$app/MacOS/$exe", $launcher, 0755);
foreach (['arm64' => $arm, 'x86_64' => $intel] as $arch => $file) {
    $zip->addFile($file, "$app/MacOS/$exe-$arch");
    $zip->setExternalAttributesName("$app/MacOS/$exe-$arch", ZipArchive::OPSYS_UNIX, (0755 | 0100000) << 16);
}
if ($icns !== '') {
    $add("$app/Resources/icon.icns", $icns, 0644);
}
$zip->close() || exit(fwrite(STDERR, "cannot write $out\n") ? 1 : 1);
echo "OUT=$out\n";
