<?php

namespace JumPHP\Renderer;

use JumPHP\Util\Color;

/**
 * Text with a TrueType font of the game (assets/fonts/*.ttf), rendered once per (text, colour) and
 * cached as a texture (LRU): drawing the same score every frame costs one SDL_RenderCopy.
 *
 *   $font = new TtfFont($sdl, 'assets/fonts/pixel.ttf', 8);   // size in view pixels
 *   $font->draw($r, 'Hello', 10, 10);
 *
 * $smooth = false renders "solid" (no anti-aliasing): best for pixel fonts. $style: SDL_ttf's style bits
 * (BOLD, ITALIC). $thicken (view pixels): the glyphs drawn that much bolder (FreeType's stroke, in their own
 * colour: a thin font made bold, e.g. the default instance of a variable font). $outline (view pixels): the
 * shadow colour of draw() becomes an outline of that width around the letters (instead of a copy one pixel
 * lower), readable over anything.
 *
 * With an HD renderer (Renderer::setHd()) the glyphs are rendered at the size they get on the view texture
 * (size x scale x hd: one font opened per pixel size) and drawn back at their view size: sharp at any scale.
 * Sizes and widths stay in view pixels.
 */
class TtfFont implements TextDrawer
{
    private const CACHE_SIZE = 192;
    /** SDL_ttf's style bits (the binding has no constants for them) */
    public const BOLD = 1;
    public const ITALIC = 2;

    private static bool $ttfReady = false;
    private $font;
    private string $file;
    /** @var array<string, mixed> the font opened at other pixel sizes / strokes ("px:stroke") */
    private array $sized = [];
    /** @var array<string, array{texture: mixed, w: int, h: int}> */
    private array $cache = [];

    public function __construct(private Sdl $sdl, string $path, private int $size, private bool $smooth = false, private int $style = 0,
                                private float $thicken = 0.0, private float $outline = 0.0)
    {
        if (!self::$ttfReady) {
            \TTF_Init();
            self::$ttfReady = true;
        }
        $this->file = \JumPHP\Kernel\Platform::isAbsolutePath($path) ? $path : $this->resolve($path);
        $this->font = \TTF_OpenFont($this->file, $size);
        if (!$this->font) {
            throw new \RuntimeException("TTF_OpenFont({$this->file}) failed: " . \SDL_GetError());
        }
        if ($style !== 0) {
            \TTF_SetFontStyle($this->font, $style);
        }
    }

    public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = [255, 255, 255],
                         string $align = 'left', Color|array|string|null $shadow = null): void
    {
        [$px, $back, $k] = $this->pixels($scale);
        $thick = (int)round($this->thicken * $k);
        $edge = (int)round($this->outline * $k);
        if ($shadow !== null && $edge <= 0) {
            $this->draw($r, $text, $x + $scale, $y + $scale, $scale, $shadow, $align);
        }
        foreach (explode("\n", $text) as $n => $line) {
            if ($line === '') {
                continue;
            }
            $w = $this->lineWidth($px, $line) * $back;
            $lineX = $align === 'center' ? $x - $w / 2 : ($align === 'right' ? $x - $w : $x);
            $lineY = $y + $n * $this->lineHeight($scale);
            if ($shadow !== null && $edge > 0) {
                // the outline: the same letters stroked wider, in the shadow's colour, under them
                $s = $thick + $edge;
                $entry = $this->render($px, $s, $line, Color::of($shadow));
                $r->copy($entry['texture'], null, $lineX - $s * $back, $lineY - $s * $back + 0.35 * $scale, $entry['w'] * $back, $entry['h'] * $back);
            }
            $entry = $this->render($px, $thick, $line, Color::of($color));
            $r->copy($entry['texture'], null, $lineX - $thick * $back, $lineY - $thick * $back, $entry['w'] * $back, $entry['h'] * $back);
        }
    }

    public function width(string $text, float $scale = 1): int
    {
        [$px, $back] = $this->pixels($scale);
        $max = 0;
        foreach (explode("\n", $text) as $line) {
            $max = max($max, $this->lineWidth($px, $line));
        }
        return (int)round($max * $back);
    }

    public function height(float $scale = 1): int
    {
        [$px, $back] = $this->pixels($scale);
        return (int)round(\TTF_FontHeight($this->font($px, 0)) * $back);
    }

    public function lineHeight(float $scale = 1): int
    {
        [$px, $back] = $this->pixels($scale);
        return (int)round(\TTF_FontLineSkip($this->font($px, 0)) * $back);
    }

    /** The width of one line in rendered pixels (without the strokes). */
    private function lineWidth(int $px, string $line): int
    {
        $w = $h = 0;
        \TTF_SizeUTF8($this->font($px, 0), $line, $w, $h);
        return (int)$w;
    }

    /**
     * The pixel size the glyphs are rendered at, what a rendered pixel is worth in view pixels, and how many
     * rendered pixels a view pixel has (for the strokes): without HD the font's own size, its texture scaled by
     * $scale; in HD the real size, drawn back at 1 / hd.
     * @return array{0: int, 1: float, 2: float}
     */
    private function pixels(float $scale): array
    {
        $k = $this->sdl->getRenderer()->hd();
        if ($k <= 1) {
            return [$this->size, $scale, 1.0];
        }
        return [max(1, (int)round($this->size * $scale * $k)), 1.0 / $k, $scale * $k];
    }

    /** The font at a pixel size, its outlines stroked $stroke pixels wider, opened the first time it is needed. */
    private function font(int $px, int $stroke)
    {
        if ($px === $this->size && $stroke === 0) {
            return $this->font;
        }
        $key = "$px:$stroke";
        if (!isset($this->sized[$key])) {
            $font = $this->open($px);
            if ($font) {
                if ($this->style !== 0) {
                    \TTF_SetFontStyle($font, $this->style);
                }
                if ($stroke > 0) {
                    \TTF_SetFontOutline($font, $stroke);
                }
            }
            $this->sized[$key] = $font;
        }
        return $this->sized[$key] ?: $this->font;
    }

    /** The font file at a pixel size: a font, or false (the binding returns false when it cannot read the file). */
    private function open(int $px): mixed
    {
        return \TTF_OpenFont($this->file, $px);
    }

    private function render(int $px, int $stroke, string $text, Color $color): array
    {
        $key = $px . ':' . $stroke . '|' . $color->key() . '|' . $text;
        if (isset($this->cache[$key])) {
            // LRU: move to the end
            $entry = $this->cache[$key];
            unset($this->cache[$key]);
            return $this->cache[$key] = $entry;
        }
        $sdlColor = new \SDL_Color($color->r, $color->g, $color->b, $color->a);
        $font = $this->font($px, $stroke);
        $surface = $this->smooth
            ? \TTF_RenderUTF8_Blended($font, $text, $sdlColor)
            : \TTF_RenderUTF8_Solid($font, $text, $sdlColor);
        $texture = \SDL_CreateTextureFromSurface($this->sdl->getRenderer()->getRenderer(), $surface);
        $format = $access = $w = $h = 0;
        \SDL_QueryTexture($texture, $format, $access, $w, $h);
        \SDL_FreeSurface($surface);

        if (count($this->cache) >= self::CACHE_SIZE) {
            $oldest = array_key_first($this->cache);
            \SDL_DestroyTexture($this->cache[$oldest]['texture']);
            unset($this->cache[$oldest]);
        }
        return $this->cache[$key] = ['texture' => $texture, 'w' => (int)$w, 'h' => (int)$h];
    }

    private function resolve(string $path): string
    {
        $root = defined('JUMPHP_ROOT') ? JUMPHP_ROOT : getcwd();
        return rtrim((string)$root, '/') . '/' . $path;
    }
}
