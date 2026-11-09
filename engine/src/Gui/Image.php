<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A picture: `src` = a file of the app (assets/images/logo.png) or any file on the disk (one the user chose:
 * `$this->photo->load($path)`). `fit`: contain | cover | fill | none; `radius` (dp) rounds nothing but the
 * placeholder; without a height it keeps the picture's proportions in the width it gets.
 * Event: click.
 *
 * @property string $src
 * @property string $fit
 */
class Image extends Widget
{
    public const DEFAULTS = ['src' => '', 'fit' => 'contain', 'background' => ''];

    /** Shows another picture (a file on the disk, a downloaded one...). */
    public function load(string $path): void
    {
        Picture::forget($this->form?->sdlOrNull(), $path);
        $this->src = $path;
    }

    public function stretches(string $axis): bool
    {
        return $axis === 'width' && !is_numeric($this->props['width']);
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $size = Picture::size($this->form?->sdlOrNull(), (string)$this->props['src']) ?? [160, 120];
        [$pw, $ph] = [$t->px($size[0]), $t->px($size[1])];
        $w = is_numeric($this->props['width']) ? $t->px((float)$this->props['width']) : min($pw, $maxWidth);
        $h = $pw > 0 ? $w * $ph / $pw : $ph;
        return [$w, $h];
    }

    public function measure(Theme $t, float $maxWidth, string $breakpoint): array
    {
        // the height follows the width it is given (a stretched picture keeps its proportions)
        [$w, $h] = parent::measure($t, $maxWidth, $breakpoint);
        if (!is_numeric($this->props['height']) && !is_numeric($this->props['width']) && $this->stretches('width')) {
            $size = Picture::size($this->form?->sdlOrNull(), (string)$this->props['src']) ?? [160, 120];
            $h = $maxWidth * $size[1] / max(1, $size[0]);
            $w = $maxWidth;
            if ($size[0] * $t->scale < $maxWidth) {     // not bigger than the picture
                [$w, $h] = [$t->px($size[0]), $t->px($size[1])];
            }
        }
        return [$w, $h];
    }

    public function render(Renderer $r, Theme $t): void
    {
        if ($this->props['background'] !== '') {
            $r->fillRect($this->x, $this->y, $this->w, $this->h, $this->tint($t, 'background', 'surface'));
        }
        if (!Picture::draw($r, $this->form, (string)$this->props['src'], $this->x, $this->y, $this->w, $this->h, (string)$this->props['fit'])) {
            // no picture yet: a frame with a cross, as in the designer
            $t->frame($r, $this->x, $this->y, $this->w, $this->h, $t->color('track'), $t->color('border'), 0);
            $r->drawLine($this->x, $this->y, $this->x + $this->w, $this->y + $this->h, $t->color('border'));
            $r->drawLine($this->x + $this->w, $this->y, $this->x, $this->y + $this->h, $t->color('border'));
        }
    }
}
