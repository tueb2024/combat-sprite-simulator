<?php

namespace JumPHP\Debug;

use JumPHP\Renderer\Sdl;

/**
 * A frame for a viewer (the Claude Code pane jumphp-live): the PNG, and beside it a small raw copy that any
 * terminal can draw as coloured blocks (`<name>.rgb`: "JRGB", width and height as little-endian u16, then the
 * RGB bytes, row by row, WIDTH pixels wide at most). Each file is written then renamed: a viewer reads whole
 * pictures only.
 */
final class LiveFrame
{
    public const WIDTH = 160;

    public static function write(Sdl $sdl, string $png): bool
    {
        @mkdir(dirname($png), 0777, true);
        $base = (string)preg_replace('/\.png$/', '', $png);
        $tmp = "$base.tmp.png";
        if (!$sdl->saveScreenshot($tmp)) {
            return false;
        }
        $image = @imagecreatefrompng($tmp);
        if ($image !== false) {
            $width = imagesx($image);
            $height = imagesy($image);
            $w = min(self::WIDTH, $width);
            $h = max(1, (int)round($height * $w / max(1, $width)));
            $small = imagecreatetruecolor($w, $h);
            imagecopyresized($small, $image, 0, 0, 0, 0, $w, $h, $width, $height);
            $bytes = 'JRGB' . pack('vv', $w, $h);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgb = imagecolorat($small, $x, $y);
                    $bytes .= chr(($rgb >> 16) & 0xff) . chr(($rgb >> 8) & 0xff) . chr($rgb & 0xff);
                }
            }
            if (@file_put_contents("$base.tmp.rgb", $bytes) !== false) {
                @rename("$base.tmp.rgb", "$base.rgb");
            }
        }
        return @rename($tmp, $png);
    }
}
