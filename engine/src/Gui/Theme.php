<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\BitmapFont;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Renderer\TtfFont;
use JumPHP\Util\Color;

/**
 * How a form looks: its colours (light or dark), its sizes and its fonts. Sizes in forms are "dp" (density
 * independent pixels): the theme's scale turns them into pixels of the view (2 on a HiDPI screen or a phone), so a
 * button is as big on every screen. Fonts: the app's assets/fonts/ui.ttf (and ui-bold.ttf), else the engine's
 * bitmap font.
 */
final class Theme
{
    public const LIGHT = [
        'background' => '#f3f4f7', 'surface' => '#ffffff', 'text' => '#1f2329', 'muted' => '#6b7280', 'border' => '#cfd4dc',
        'accent' => '#3b6ef5', 'accentText' => '#ffffff', 'hover' => '#e8ecf3', 'pressed' => '#d5dbe6', 'focus' => '#3b6ef5',
        'selection' => '#cfdcff', 'danger' => '#d64545', 'disabled' => '#a9afb9', 'track' => '#dde1e8',
    ];

    public const DARK = [
        'background' => '#1e1f22', 'surface' => '#2b2d31', 'text' => '#e6e7ea', 'muted' => '#9aa0aa', 'border' => '#43464d',
        'accent' => '#5b8cff', 'accentText' => '#ffffff', 'hover' => '#35383e', 'pressed' => '#40444b', 'focus' => '#5b8cff',
        'selection' => '#33476e', 'danger' => '#ef6464', 'disabled' => '#6b6f77', 'track' => '#3a3d43',
    ];

    /** @var array<string, string> */
    public array $colors;

    /** pixels per dp */
    public float $scale = 1.0;

    /** dp: the default text size, corner radius, control height, inner padding */
    public float $fontSize = 15;
    public float $radius = 6;
    public float $controlHeight = 36;
    public float $padding = 10;

    /** @var array<string, TextDrawer> size|bold => font */
    private array $fonts = [];
    private ?bool $hasTtf = null;

    public function __construct(private ?Sdl $sdl = null, string $mode = 'light', private string $fontDir = 'assets/fonts')
    {
        $this->colors = $mode === 'dark' ? self::DARK : self::LIGHT;
    }

    public function color(string $name): Color
    {
        return Color::of($this->colors[$name] ?? $name);
    }

    /** dp -> view pixels */
    public function px(float $dp): float
    {
        return $dp * $this->scale;
    }

    /** The font for a text size in dp (cached per pixel size). */
    public function font(?float $size = null, bool $bold = false): TextDrawer
    {
        $px = max(6, (int)round(($size ?? $this->fontSize) * $this->scale));
        $key = $px . ($bold ? 'b' : '');
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }
        if ($this->sdl !== null && $this->ttf($bold) !== null) {
            try {
                return $this->fonts[$key] = new TtfFont($this->sdl, (string)$this->ttf($bold), $px, true);
            } catch (\Throwable) {
                $this->hasTtf = false;
            }
        }
        // no TTF: the pixel font (7 px high glyphs, 9 px lines), scaled; no SDL at all (layout tests): estimated sizes
        return $this->fonts[$key] = $this->sdl !== null ? new ScaledFont(new BitmapFont($this->sdl), $px / 9) : new EstimatedFont($px);
    }

    private function ttf(bool $bold): ?string
    {
        if ($this->hasTtf === false || !extension_loaded('sdl_ttf') && !function_exists('TTF_OpenFont')) {
            return null;
        }
        $root = defined('JUMPHP_ROOT') ? (string)JUMPHP_ROOT : (string)getcwd();
        foreach ($bold ? ['ui-bold.ttf', 'ui.ttf'] : ['ui.ttf'] as $name) {
            $file = rtrim($root, '/') . '/' . trim($this->fontDir, '/') . '/' . $name;
            if (is_file($file)) {
                $this->hasTtf = true;
                return $this->fontDir . '/' . $name;
            }
        }
        $this->hasTtf = false;
        return null;
    }

    /** A filled box with round corners (radius in pixels). */
    public function box(Renderer $r, float $x, float $y, float $w, float $h, Color|array|string $color, float $radius = -1): void
    {
        $radius = $radius < 0 ? $this->px($this->radius) : $radius;
        $radius = min($radius, $w / 2, $h / 2);
        if ($radius < 1.5 || $w < 4 || $h < 4) {
            $r->fillRect($x, $y, $w, $h, $color);
            return;
        }
        $c = Color::of($color);
        $n = (int)round($radius);
        $corner = $n >= 2 && $this->sdl !== null ? $this->corner($n, $c) : null;
        if ($corner === null) {
            // no SDL (layout tests): scanline circles
            $r->fillRect($x + $radius, $y, $w - 2 * $radius, $h, $c);
            $r->fillRect($x, $y + $radius, $radius, $h - 2 * $radius, $c);
            $r->fillRect($x + $w - $radius, $y + $radius, $radius, $h - 2 * $radius, $c);
            foreach ([[$x + $radius, $y + $radius], [$x + $w - $radius - 1, $y + $radius], [$x + $radius, $y + $h - $radius - 1], [$x + $w - $radius - 1, $y + $h - $radius - 1]] as [$cx, $cy]) {
                $r->fillCircle($cx, $cy, $radius, $c);
            }
            return;
        }
        // the three bands, and a cached quarter circle in each corner (a scanline circle is dozens of fillRect calls, a form has
        // hundreds of rounded boxes, drawn every frame: this was most of a frame's time on a phone)
        $r->fillRect($x + $n, $y, $w - 2 * $n, $h, $c);
        $r->fillRect($x, $y + $n, $n, $h - 2 * $n, $c);
        $r->fillRect($x + $w - $n, $y + $n, $n, $h - 2 * $n, $c);
        $r->copy($corner, null, $x, $y, $n, $n);
        $r->copy($corner, null, $x + $w - $n, $y, $n, $n, true, false);
        $r->copy($corner, null, $x, $y + $h - $n, $n, $n, false, true);
        $r->copy($corner, null, $x + $w - $n, $y + $h - $n, $n, $n, true, true);
    }

    private const CORNERS = 96;

    /** @var array<string, string> radius|colour => texture name, oldest first */
    private array $corners = [];

    /** The top-left quarter of a disc of $n pixels in a colour (anti-aliased), as a texture: made once. */
    private function corner(int $n, Color $c): mixed
    {
        $key = $n . '|' . $c->key();
        $name = 'gui-corner-' . $key;
        if (isset($this->corners[$key])) {
            unset($this->corners[$key]);
            $this->corners[$key] = $name;
            return $this->sdl->getTexture($name)['texture'] ?? null;
        }
        $image = imagecreatetruecolor($n, $n);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        for ($j = 0; $j < $n; $j++) {
            for ($i = 0; $i < $n; $i++) {
                $d = sqrt(($n - $i - 0.5) ** 2 + ($n - $j - 0.5) ** 2);
                $cover = max(0.0, min(1.0, $n - $d + 0.5));
                if ($cover > 0) {
                    imagesetpixel($image, $i, $j, imagecolorallocatealpha($image, $c->r, $c->g, $c->b, 127 - min(127, (int)round($c->a / 2 * $cover))));
                }
            }
        }
        try {
            $slices = $this->sdl->createTextureFromGd($name, $image);
        } catch (\Throwable) {
            return null;
        }
        $this->corners[$key] = $name;
        while (count($this->corners) > self::CORNERS) {
            $old = array_key_first($this->corners);
            $this->sdl->releaseTexture($this->corners[$old]);
            unset($this->corners[$old]);
        }
        return $slices[0]['texture'] ?? null;
    }

    /** A box with a border of 1 dp (two boxes: the border colour, then the fill). */
    public function frame(Renderer $r, float $x, float $y, float $w, float $h, Color|array|string $fill, Color|array|string $border, float $radius = -1): void
    {
        $radius = $radius < 0 ? $this->px($this->radius) : $radius;
        $b = max(1.0, round($this->scale));
        $this->box($r, $x, $y, $w, $h, $border, $radius);
        $this->box($r, $x + $b, $y + $b, $w - 2 * $b, $h - 2 * $b, $fill, max(0, $radius - $b));
    }
}
