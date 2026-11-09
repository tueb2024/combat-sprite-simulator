<?php

namespace JumPHP\Renderer;

use JumPHP\Util\Color;

/**
 * Draws the game at its virtual resolution (game.yaml width x height) into a render-target texture,
 * then scales it to the window with letterboxing and nearest-neighbour filtering: crisp retro pixels
 * at any window size.
 *
 * HD (game.yaml `hd`, setHd()): the view texture is made hd() times bigger and SDL scales every drawing call
 * to it, so the coordinates stay view pixels while text (TtfFont renders at the real size), circles and
 * whatever is placed at a fraction of a view pixel come out sharp; sprites stay crisp (nearest).
 *
 * All coordinates are view pixels. The SDL binding has no SDL_SetRenderDrawBlendMode: a colour with
 * alpha < 255 is drawn with a cached 1x1 translucent texture instead (fillRect handles it).
 */
class Renderer
{
    private $renderer = null;
    private $viewTexture = null;
    private $currentTarget = null;
    private int $viewWidth;
    private int $viewHeight;
    private bool $integerScale = false;
    /** HD wanted: 1 = off, 0 = follow the window, 2..4 = fixed */
    private int $hdMode = 1;
    /** HD of the view texture now, and for the next frame */
    private int $hd = 1;
    private int $hdNext = 1;
    /** @var array<string, mixed> 1x1 textures of translucent colours */
    private array $alphaTextures = [];
    /** @var array{0:int,1:int,2:int,3:int} letterbox of the last frame */
    private array $letterbox = [0, 0, 0, 0];
    /** @var array<int, callable> drawn after the scaled view, at window resolution */
    private array $overlays = [];
    /** @var array<int, callable(Renderer): void> end of the frame, the view texture still the target (OpenGL compositing) */
    private array $viewHooks = [];
    /** @var array<int, callable(Renderer): void> end of the frame, the view just copied to the window, before the overlays */
    private array $windowHooks = [];
    /** @var (callable(Renderer): void)|null right before the picture is presented (headless OpenGL screenshots) */
    private $capture = null;
    private bool $software = false;
    /** @var resource|object|null */
    private $window = null;

    /** @param resource|object|null $window SDL window, or null with $surface (headless: software renderer) */
    public function __construct($window, int $viewWidth, int $viewHeight, bool $vsync = true, $surface = null)
    {
        \SDL_SetHint(\SDL_HINT_RENDER_SCALE_QUALITY, '0'); // nearest: crisp pixels
        $this->window = $window;
        if ($surface !== null) {
            $this->renderer = \SDL_CreateSoftwareRenderer($surface);
            $this->software = true;
        } else {
            $flags = \SDL_RENDERER_ACCELERATED | ($vsync ? \SDL_RENDERER_PRESENTVSYNC : 0);
            $this->renderer = \SDL_CreateRenderer($window, -1, $flags);
            if (!$this->renderer) {
                // no GPU (VM, remote X): software rendering still works
                $this->renderer = \SDL_CreateRenderer($window, -1, \SDL_RENDERER_SOFTWARE);
                $this->software = true;
            }
        }
        if (!$this->renderer) {
            throw new \RuntimeException('SDL_CreateRenderer failed: ' . \SDL_GetError());
        }
        $this->resizeView($viewWidth, $viewHeight);
    }

    /** The raw SDL renderer, for direct \SDL_* calls. */
    public function getRenderer()
    {
        return $this->renderer;
    }

    public function getWidth(): int
    {
        return $this->viewWidth;
    }

    public function getHeight(): int
    {
        return $this->viewHeight;
    }

    /** Whole-number scaling only (pixel-perfect, bigger black borders). */
    public function setIntegerScale(bool $on): void
    {
        $this->integerScale = $on;
    }

    /** New virtual resolution (zoom, other aspect ratio). */
    public function resizeView(int $width, int $height): void
    {
        $wasTarget = $this->viewTexture !== null && $this->currentTarget === $this->viewTexture;
        if ($this->viewTexture) {
            \SDL_DestroyTexture($this->viewTexture);
        }
        $this->viewWidth = $width;
        $this->viewHeight = $height;
        $this->viewTexture = $this->createTargetTexture($width * $this->hd, $height * $this->hd);
        if ($wasTarget) {
            $this->setTarget($this->viewTexture);
        }
    }

    /**
     * Draws the 2D at $factor times the view's size, its coordinates unchanged: sharp text, shapes and
     * positions at fractions of a view pixel. 1 = off (retro pixels, the default), 0 = follow the window (its
     * scale, 1 to 4), 2..4 = fixed. Takes effect at the next frame.
     */
    public function setHd(int $factor): void
    {
        $this->hdMode = max(0, min(4, $factor));
        $this->hdNext = $this->hdMode === 0 ? $this->windowHd() : max(1, $this->hdMode);
        if ($this->currentTarget === null) {
            $this->applyHd();
        }
    }

    /** How many texture pixels a view pixel has (1: no HD). */
    public function hd(): int
    {
        return $this->hd;
    }

    /** The view texture's size in pixels (the view's size x hd()). @return array{0: int, 1: int} */
    public function viewPixels(): array
    {
        return [$this->viewWidth * $this->hd, $this->viewHeight * $this->hd];
    }

    /** The HD that fits the window now (the window must be the target: SDL reports the target's size). */
    private function windowHd(): int
    {
        [$ow, $oh] = $this->outputSize();
        $scale = min($ow / max(1, $this->viewWidth), $oh / max(1, $this->viewHeight));
        return max(1, min(4, (int)round($scale)));
    }

    /** A new view texture when the HD changed (between frames). */
    private function applyHd(): void
    {
        if ($this->hdNext === $this->hd) {
            return;
        }
        $this->hd = $this->hdNext;
        $this->resizeView($this->viewWidth, $this->viewHeight);
    }

    public function createTargetTexture(int $width, int $height)
    {
        $texture = \SDL_CreateTexture($this->renderer, \SDL_PIXELFORMAT_RGBA8888, \SDL_TEXTUREACCESS_TARGET, $width, $height);
        if (!$texture) {
            throw new \RuntimeException("SDL_CreateTexture({$width}x{$height}) failed: " . \SDL_GetError());
        }
        return $texture;
    }

    public function getViewTexture()
    {
        return $this->viewTexture;
    }

    // --- frame -----------------------------------------------------------------------------------

    public function beginFrame(Color|array|string $background = [0, 0, 0]): void
    {
        $this->applyHd();
        $this->setTarget($this->viewTexture);
        $this->clear($background);
    }

    public function endFrame(): void
    {
        $this->setTarget($this->viewTexture);
        foreach ($this->viewHooks as $hook) {
            $hook($this);
        }
        $this->setTarget(null);
        \SDL_SetRenderDrawColor($this->renderer, 0, 0, 0, 255);
        \SDL_RenderClear($this->renderer);
        if ($this->hdMode === 0) {
            $this->hdNext = $this->windowHd();
        }
        [$x, $y, $w, $h] = $this->letterbox = $this->computeLetterbox();
        \SDL_RenderCopy($this->renderer, $this->viewTexture, null, new \SDL_Rect($x, $y, $w, $h));
        foreach ($this->windowHooks as $hook) {
            $hook($this);
        }
        foreach ($this->overlays as $overlay) {
            $overlay($this);
        }
        if ($this->capture !== null) {
            $this->flush();
            ($this->capture)($this);
        }
        \SDL_RenderPresent($this->renderer);
    }

    /** No GPU renderer (headless surface, or no accelerated driver): no OpenGL context either. */
    public function isSoftware(): bool
    {
        return $this->software;
    }

    /**
     * Draws what SDL has queued (render batching) now: raw OpenGL calls come after it. Switching the target
     * flushes SDL's queue (the binding has no SDL_RenderFlush).
     */
    public function flush(): void
    {
        $target = $this->currentTarget;
        \SDL_SetRenderTarget($this->renderer, $target === null ? $this->viewTexture : null);
        $this->setTarget($target);
    }

    /**
     * $hook(Renderer) at the end of each frame: 'view' = the view texture is the target (before it is scaled),
     * 'window' = the window is the target (the view already copied, before the overlays).
     */
    public function addFrameHook(string $where, callable $hook): void
    {
        if ($where === 'view') {
            $this->viewHooks[] = $hook;
        } else {
            $this->windowHooks[] = $hook;
        }
    }

    /** @param (callable(Renderer): void)|null $capture */
    public function setCapture(?callable $capture): void
    {
        $this->capture = $capture;
    }

    /** $draw(Renderer) runs after the view is scaled: draws at window resolution (sharp UI, debug). */
    public function addOverlay(callable $draw): int
    {
        $this->overlays[] = $draw;
        return array_key_last($this->overlays);
    }

    public function removeOverlay(int $id): void
    {
        unset($this->overlays[$id]);
    }

    /** Draws into $texture (null: the window); returns the previous target. */
    public function setTarget($texture)
    {
        $previous = $this->currentTarget;
        $this->currentTarget = $texture;
        \SDL_SetRenderTarget($this->renderer, $texture);
        if ($this->hd > 1 && $texture !== null && $texture === $this->viewTexture) {
            // SDL resets the scale of each new target: the HD view texture is drawn in view pixels, scaled by SDL
            \SDL_RenderSetLogicalSize($this->renderer, $this->viewWidth, $this->viewHeight);
        }
        return $previous;
    }

    public function getTarget()
    {
        return $this->currentTarget;
    }

    /** @return array{0:int,1:int} window (or screenshot) size in physical pixels */
    public function outputSize(): array
    {
        $w = 0;
        $h = 0;
        \SDL_GetRendererOutputSize($this->renderer, $w, $h);
        return [(int)$w, (int)$h];
    }

    /** @return array{0:int,1:int,2:int,3:int} where the view lands in the window: x, y, w, h */
    public function letterbox(): array
    {
        return $this->letterbox;
    }

    /** Window pixel (logical: CSS pixels in the browser) -> view pixel (mouse, touch). */
    public function windowToView(float $px, float $py): array
    {
        [$x, $y, $w, $h] = $this->letterbox;
        if ($w === 0 || $h === 0) {
            return [$px, $py];
        }
        if ($this->window !== null) {
            // HiDPI (phones: 2-3 output pixels per CSS pixel): the letterbox is in output pixels
            $ww = 0;
            $wh = 0;
            \SDL_GetWindowSize($this->window, $ww, $wh);
            [$ow, $oh] = $this->outputSize();
            if ($ww > 0 && $wh > 0) {
                $px *= $ow / $ww;
                $py *= $oh / $wh;
            }
        }
        return [($px - $x) * $this->viewWidth / $w, ($py - $y) * $this->viewHeight / $h];
    }

    /** The other way: a point of the view in window coordinates (logical pixels). */
    public function viewToWindow(float $vx, float $vy): array
    {
        [$x, $y, $w, $h] = $this->letterbox;
        if ($w === 0 || $h === 0 || $this->viewWidth === 0 || $this->viewHeight === 0) {
            return [$vx, $vy];
        }
        $px = $x + $vx * $w / $this->viewWidth;
        $py = $y + $vy * $h / $this->viewHeight;
        if ($this->window !== null) {
            $ww = 0;
            $wh = 0;
            \SDL_GetWindowSize($this->window, $ww, $wh);
            [$ow, $oh] = $this->outputSize();
            if ($ww > 0 && $wh > 0 && $ow > 0 && $oh > 0) {
                $px *= $ww / $ow;
                $py *= $wh / $oh;
            }
        }
        return [$px, $py];
    }

    private function computeLetterbox(): array
    {
        [$ow, $oh] = $this->outputSize();
        $scale = min($ow / $this->viewWidth, $oh / $this->viewHeight);
        if ($this->integerScale && $scale >= 1) {
            $scale = floor($scale);
        }
        $w = (int)round($this->viewWidth * $scale);
        $h = (int)round($this->viewHeight * $scale);
        return [intdiv($ow - $w, 2), intdiv($oh - $h, 2), $w, $h];
    }

    // --- drawing -----------------------------------------------------------------------------------

    public function setColor(int $r, int $g, int $b, int $a = 255): void
    {
        \SDL_SetRenderDrawColor($this->renderer, $r, $g, $b, $a);
    }

    /**
     * Fills the target with an opaque colour. $keepAlpha: the colour's alpha is kept, the target left that transparent
     * (clear([0, 0, 0, 0], true) under a WebGL layer composed by the 2D's alpha: WebGl::setLayer(..., null)).
     */
    public function clear(Color|array|string $color = [0, 0, 0], bool $keepAlpha = false): void
    {
        $c = Color::of($color);
        \SDL_SetRenderDrawColor($this->renderer, $c->r, $c->g, $c->b, $keepAlpha ? $c->a : 255);
        \SDL_RenderClear($this->renderer);
    }

    /**
     * Moves what is drawn next by (dx, dy) (cumulative; translate(-dx, -dy) to undo). The GUI draws a scrolled area into a
     * texture of its own with it, its widgets keeping their coordinates in the view.
     */
    public function translate(float $dx, float $dy): void
    {
        $this->ox += $dx;
        $this->oy += $dy;
    }

    /** @return array{0: float, 1: float} */
    public function translation(): array
    {
        return [$this->ox, $this->oy];
    }

    private float $ox = 0;
    private float $oy = 0;

    public function fillRect(float $x, float $y, float $w, float $h, Color|array|string $color): void
    {
        $x += $this->ox;
        $y += $this->oy;
        $c = Color::of($color);
        if ($c->a <= 0) {
            return;
        }
        if ($this->hd > 1) {
            // HD: fractions of a view pixel are real pixels
            $rect = new \SDL_FRect($x, $y, $w, $h);
            if ($c->a < 255) {
                \SDL_RenderCopyF($this->renderer, $this->alphaTexture($c), null, $rect);
                return;
            }
            \SDL_SetRenderDrawColor($this->renderer, $c->r, $c->g, $c->b, 255);
            \SDL_RenderFillRectF($this->renderer, $rect);
            return;
        }
        $rect = new \SDL_Rect((int)round($x), (int)round($y), (int)round($w), (int)round($h));
        if ($c->a < 255) {
            \SDL_RenderCopy($this->renderer, $this->alphaTexture($c), null, $rect);
            return;
        }
        \SDL_SetRenderDrawColor($this->renderer, $c->r, $c->g, $c->b, 255);
        \SDL_RenderFillRect($this->renderer, $rect);
    }

    public function drawRect(float $x, float $y, float $w, float $h, Color|array|string $color, int $thickness = 1): void
    {
        $this->fillRect($x, $y, $w, $thickness, $color);
        $this->fillRect($x, $y + $h - $thickness, $w, $thickness, $color);
        $this->fillRect($x, $y + $thickness, $thickness, $h - 2 * $thickness, $color);
        $this->fillRect($x + $w - $thickness, $y + $thickness, $thickness, $h - 2 * $thickness, $color);
    }

    public function drawLine(float $x1, float $y1, float $x2, float $y2, Color|array|string $color): void
    {
        $c = Color::of($color);
        \SDL_SetRenderDrawColor($this->renderer, $c->r, $c->g, $c->b, 255);
        if ($this->hd > 1) {
            \SDL_RenderDrawLineF($this->renderer, $x1 + $this->ox, $y1 + $this->oy, $x2 + $this->ox, $y2 + $this->oy);
            return;
        }
        \SDL_RenderDrawLine($this->renderer, (int)round($x1 + $this->ox), (int)round($y1 + $this->oy), (int)round($x2 + $this->ox), (int)round($y2 + $this->oy));
    }

    public function drawPoint(float $x, float $y, Color|array|string $color): void
    {
        $c = Color::of($color);
        \SDL_SetRenderDrawColor($this->renderer, $c->r, $c->g, $c->b, 255);
        if ($this->hd > 1) {
            \SDL_RenderDrawPointF($this->renderer, $x + $this->ox, $y + $this->oy);
            return;
        }
        \SDL_RenderDrawPoint($this->renderer, (int)round($x + $this->ox), (int)round($y + $this->oy));
    }

    /** Filled circle (scanlines): balls, particles. In HD, one scanline per real pixel: a round edge. */
    public function fillCircle(float $cx, float $cy, float $radius, Color|array|string $color): void
    {
        if ($this->hd > 1) {
            $k = $this->hd;
            $rows = (int)ceil($radius * $k);
            for ($i = -$rows; $i < $rows; $i++) {
                $dy = ($i + 0.5) / $k;
                $dx = sqrt(max(0.0, $radius * $radius - $dy * $dy));
                if ($dx > 0) {
                    $this->fillRect($cx - $dx, $cy + $i / $k, 2 * $dx, 1 / $k, $color);
                }
            }
            return;
        }
        $r = (int)round($radius);
        for ($dy = -$r; $dy <= $r; $dy++) {
            $dx = (int)floor(sqrt($r * $r - $dy * $dy));
            $this->fillRect($cx - $dx, $cy + $dy, 2 * $dx + 1, 1, $color);
        }
    }

    /**
     * Draws a texture (or a part of it). $src = [x, y, w, h] in the texture, null for all of it.
     * $w / $h default to the source size.
     */
    public function copy($texture, ?array $src, float $x, float $y, ?float $w = null, ?float $h = null,
                         bool $flipH = false, bool $flipV = false, float $angle = 0.0): void
    {
        $srcRect = $src === null ? null : new \SDL_Rect((int)$src[0], (int)$src[1], (int)$src[2], (int)$src[3]);
        if ($w === null || $h === null) {
            if ($src !== null) {
                $w ??= $src[2];
                $h ??= $src[3];
            } else {
                $format = $access = $tw = $th = 0;
                \SDL_QueryTexture($texture, $format, $access, $tw, $th);
                $w ??= $tw;
                $h ??= $th;
            }
        }
        $flip = ($flipH ? \SDL_FLIP_HORIZONTAL : 0) | ($flipV ? \SDL_FLIP_VERTICAL : 0);
        if ($this->hd > 1) {
            $dst = new \SDL_FRect($x + $this->ox, $y + $this->oy, $w, $h);
            if ($flip === 0 && $angle == 0.0) {
                \SDL_RenderCopyF($this->renderer, $texture, $srcRect, $dst);
            } else {
                \SDL_RenderCopyExF($this->renderer, $texture, $srcRect, $dst, $angle, null, $flip);
            }
            return;
        }
        $dst = new \SDL_Rect((int)round($x + $this->ox), (int)round($y + $this->oy), (int)round($w), (int)round($h));
        if ($flip === 0 && $angle == 0.0) {
            \SDL_RenderCopy($this->renderer, $texture, $srcRect, $dst);
            return;
        }
        \SDL_RenderCopyEx($this->renderer, $texture, $srcRect, $dst, $angle, null, $flip);
    }

    /** A 1x1 texture of a translucent colour, stretched by fillRect (the binding has no draw blend mode). */
    private function alphaTexture(Color $c)
    {
        // 16 alpha levels: enough for fades, a bounded cache
        $a = max(1, min(255, (int)(round($c->a / 17) * 17)));
        $key = $c->r . ',' . $c->g . ',' . $c->b . ',' . $a;
        if (isset($this->alphaTextures[$key])) {
            return $this->alphaTextures[$key];
        }
        $surface = \SDL_CreateRGBSurface(0, 1, 1, 32, 0x000000FF, 0x0000FF00, 0x00FF0000, -0x1000000);
        \SDL_FillRect($surface, new \SDL_Rect(0, 0, 1, 1), \SDL_MapRGBA($surface->format, $c->r, $c->g, $c->b, $a));
        $texture = \SDL_CreateTextureFromSurface($this->renderer, $surface);
        \SDL_FreeSurface($surface);
        if (count($this->alphaTextures) > 256) {
            foreach ($this->alphaTextures as $old) {
                \SDL_DestroyTexture($old);
            }
            $this->alphaTextures = [];
        }
        return $this->alphaTextures[$key] = $texture;
    }

    public function destroy(): void
    {
        foreach ($this->alphaTextures as $texture) {
            \SDL_DestroyTexture($texture);
        }
        $this->alphaTextures = [];
        if ($this->viewTexture) {
            \SDL_DestroyTexture($this->viewTexture);
        }
        if ($this->renderer) {
            \SDL_DestroyRenderer($this->renderer);
            $this->renderer = null;
        }
    }
}
