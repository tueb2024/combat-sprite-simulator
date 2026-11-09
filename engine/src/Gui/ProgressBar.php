<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * How far something went: `value` out of `max`; `showText` writes the percentage.
 *
 * @property float $value
 */
class ProgressBar extends Widget
{
    public const DEFAULTS = ['value' => 30, 'max' => 100, 'showText' => false];

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        return [$t->px(160), $t->px($this->props['showText'] ? 20 : 10)];
    }

    public function render(Renderer $r, Theme $t): void
    {
        $ratio = (float)$this->props['max'] > 0 ? max(0, min(1, (float)$this->props['value'] / (float)$this->props['max'])) : 0;
        $t->box($r, $this->x, $this->y, $this->w, $this->h, $t->color('track'), $this->h / 2);
        if ($ratio > 0) {
            $t->box($r, $this->x, $this->y, max($this->h, $this->w * $ratio), $this->h, $this->tint($t, 'color', 'accent'), $this->h / 2);
        }
        if ($this->props['showText']) {
            $font = $t->font($t->fontSize * 0.8, true);
            $font->draw($r, (int)round($ratio * 100) . ' %', $this->x + $this->w / 2, $this->y + ($this->h - $font->height()) / 2, 1,
                $ratio > 0.5 ? $t->color('accentText') : $t->color('text'), 'center');
        }
    }
}
