<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;

/**
 * Pictures of the widgets (Image, Button's image, Video's frames): loaded once per file, then drawn fitted in a box.
 * Fit: contain (all of it, bars around), cover (fills the box, cut), fill (stretched), none (its own size, centred).
 */
final class Picture
{
    /** @var array<string, array{0: int, 1: int}|false> file => size, false when it could not be loaded */
    private static array $loaded = [];

    /** The texture name of a file, loaded if needed; null without SDL or when the file is not a picture. */
    public static function texture(?Sdl $sdl, string $src): ?string
    {
        if ($sdl === null || $src === '') {
            return null;
        }
        $name = 'gui:' . md5($src);
        if (!isset(self::$loaded[$src]) || !$sdl->hasTexture($name)) {
            try {
                $info = $sdl->loadTexture($name, $src)[0];
                self::$loaded[$src] = [(int)$info['width'], (int)$info['height']];
            } catch (\Throwable) {
                self::$loaded[$src] = false;
            }
        }
        return self::$loaded[$src] === false ? null : $name;
    }

    /** @return array{0: int, 1: int}|null its size in pixels */
    public static function size(?Sdl $sdl, string $src): ?array
    {
        if (self::texture($sdl, $src) === null) {
            if ($sdl === null && $src !== '' && is_file($src)) {
                $size = @getimagesize($src);
                return $size ? [(int)$size[0], (int)$size[1]] : null;
            }
            return null;
        }
        return self::$loaded[$src] ?: null;
    }

    /** Forgets a file (it changed on the disk): the next draw loads it again. */
    public static function forget(?Sdl $sdl, string $src): void
    {
        unset(self::$loaded[$src]);
        $sdl?->releaseTexture('gui:' . md5($src));
    }

    public static function draw(Renderer $r, ?Form $form, string $src, float $x, float $y, float $w, float $h, string $fit = 'contain'): bool
    {
        $sdl = $form?->sdlOrNull();
        $name = self::texture($sdl, $src);
        if ($name === null || $sdl === null) {
            return false;
        }
        $tex = $sdl->getTexture($name);
        [$tw, $th] = [(float)$tex['width'], (float)$tex['height']];
        if ($tw <= 0 || $th <= 0 || $w <= 0 || $h <= 0) {
            return false;
        }
        switch ($fit) {
            case 'fill':
                $r->copy($tex['texture'], null, $x, $y, $w, $h);
                break;
            case 'cover':
                $scale = max($w / $tw, $h / $th);
                $sw = $w / $scale;
                $sh = $h / $scale;
                $r->copy($tex['texture'], [(int)(($tw - $sw) / 2), (int)(($th - $sh) / 2), (int)$sw, (int)$sh], $x, $y, $w, $h);
                break;
            case 'none':
                $dw = min($tw, $w);
                $dh = min($th, $h);
                $r->copy($tex['texture'], [(int)(($tw - $dw) / 2), (int)(($th - $dh) / 2), (int)$dw, (int)$dh], $x + ($w - $dw) / 2, $y + ($h - $dh) / 2, $dw, $dh);
                break;
            default:
                $scale = min($w / $tw, $h / $th);
                $dw = $tw * $scale;
                $dh = $th * $scale;
                $r->copy($tex['texture'], null, $x + ($w - $dw) / 2, $y + ($h - $dh) / 2, $dw, $dh);
        }
        return true;
    }
}
