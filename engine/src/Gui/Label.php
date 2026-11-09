<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A text. `size` (dp, default the theme's), `bold`, `color`, `textAlign` (left | center | right), `wrap` (long texts
 * go to the next line, default true), `style`: title | subtitle | muted.
 *
 * @property string $text
 * @property string $color
 */
class Label extends Widget
{
    public const DEFAULTS = ['text' => '', 'size' => null, 'bold' => false, 'color' => '', 'textAlign' => 'left', 'wrap' => true, 'style' => ''];

    /** @var list<string> */
    private array $lines = [];

    public static function events(): array
    {
        return ['click'];
    }

    private function fontSize(Theme $t): float
    {
        if ($this->props['size'] !== null) {
            return (float)$this->props['size'];
        }
        return match ($this->props['style']) {
            'title' => $t->fontSize * 1.8,
            'subtitle' => $t->fontSize * 1.25,
            'small', 'muted' => $t->fontSize * 0.87,
            default => $t->fontSize,
        };
    }

    private function bold(): bool
    {
        return (bool)$this->props['bold'] || in_array($this->props['style'], ['title', 'subtitle'], true);
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $font = $t->font($this->fontSize($t), $this->bold());
        $lines = $this->wrapText($t, $maxWidth);
        $w = 0;
        foreach ($lines as $line) {
            $w = max($w, $font->width($line));
        }
        return [$w, max(1, count($lines)) * $font->lineHeight()];
    }

    public function layout(Theme $t, float $x, float $y, float $w, float $h, string $breakpoint): void
    {
        parent::layout($t, $x, $y, $w, $h, $breakpoint);
        $this->lines = $this->wrapText($t, $w);
    }

    /** @return list<string> */
    private function wrapText(Theme $t, float $maxWidth): array
    {
        $font = $t->font($this->fontSize($t), $this->bold());
        $out = [];
        foreach (explode("\n", $this->tr((string)$this->props['text'])) as $paragraph) {
            if (!$this->props['wrap'] || $font->width($paragraph) <= $maxWidth) {
                $out[] = $paragraph;
                continue;
            }
            $line = '';
            foreach (explode(' ', $paragraph) as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($line !== '' && $font->width($try) > $maxWidth) {
                    $out[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    public function render(Renderer $r, Theme $t): void
    {
        $font = $t->font($this->fontSize($t), $this->bold());
        $color = $this->isEnabled() ? $this->tint($t, 'color', $this->props['style'] === 'muted' ? 'muted' : 'text') : $t->color('disabled');
        $align = (string)$this->props['textAlign'];
        $x = match ($align) {
            'center' => $this->x + $this->w / 2,
            'right' => $this->x + $this->w,
            default => $this->x,
        };
        $lh = $font->lineHeight();
        $y = $this->y + max(0, ($this->h - count($this->lines) * $lh) / 2);
        foreach ($this->lines as $n => $line) {
            $font->draw($r, $line, $x, $y + $n * $lh + ($lh - $font->height()) / 2, 1, $color, $align);
        }
    }
}
