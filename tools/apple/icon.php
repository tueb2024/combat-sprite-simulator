<?php
/*
 * icon.php <game folder> <background #rrggbb> <title> <size> <out.png>: the app's icon, as the Android app's and the
 * PWA's: assets/icon.png scaled up in whole pixels (nearest neighbour) inside the safe zone (70 %), on the
 * background, opaque (the App Store refuses an icon with alpha); without assets/icon.png, the title's initial.
 */
[, $game, $background, $title, $size, $out] = $argv + array_fill(0, 6, '');
$size = (int)$size;
$hex = ltrim($background ?: '#000000', '#');
$hex = strlen($hex) === 3 ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : str_pad($hex, 6, '0');
[$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 0, 6), 2));

$icon = imagecreatetruecolor($size, $size);
imagefill($icon, 0, 0, imagecolorallocate($icon, $r, $g, $b));

$source = is_file("$game/assets/icon.png") ? @imagecreatefrompng("$game/assets/icon.png") : false;
if (!$source) {
    // the title's initial, drawn in GD's built-in font, then scaled up as the picture would be
    $source = imagecreatetruecolor(16, 16);
    imagesavealpha($source, true);
    imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
    $initial = strtoupper(substr(trim($title) ?: 'J', 0, 1));
    imagestring($source, 5, 4, 0, $initial, imagecolorallocate($source, 0xff, 0xd2, 0x3f));
}
$w = imagesx($source);
$h = imagesy($source);
$safe = intdiv($size * 7, 10);
imagealphablending($icon, true);
if (max($w, $h) <= $safe) { // pixel art: whole pixels
    $scale = intdiv($safe, max($w, $h));
    imagecopyresized($icon, $source, intdiv($size - $w * $scale, 2), intdiv($size - $h * $scale, 2), 0, 0, $w * $scale, $h * $scale, $w, $h);
} else { // a big picture: scaled down, smoothed
    $dw = (int)round($w * $safe / max($w, $h));
    $dh = (int)round($h * $safe / max($w, $h));
    imagecopyresampled($icon, $source, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);
}
imagepng($icon, $out);
