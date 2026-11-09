<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A button. `style`: default | primary | danger | flat; `image`: a picture before the text (assets/...).
 * Event: click (also Return / Space when it has the focus).
 *
 * @property string $text
 * @property string $style
 */
class Button extends Widget
{
    public const DEFAULTS = ['text' => 'Button', 'style' => 'default', 'image' => '', 'size' => null];

    public function focusable(): bool
    {
        return $this->isEnabled();
    }

    public function stretches(string $axis): bool
    {
        return false;
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $font = $t->font($this->props['size'] ?? $t->fontSize, true);
        $icon = $this->props['image'] !== '' ? $t->px($t->fontSize * 1.3) + $t->px(6) : 0;
        return [$font->width($this->tr((string)$this->props['text'])) + $icon + 2 * $t->px($t->padding * 1.6), $t->px($t->controlHeight)];
    }

    public function render(Renderer $r, Theme $t): void
    {
        $enabled = $this->isEnabled();
        [$fill, $text, $border] = match ($this->props['style']) {
            'primary' => [$t->color('accent'), $t->color('accentText'), null],
            'danger' => [$t->color('danger'), $t->color('accentText'), null],
            'flat' => [$this->hovered ? $t->color('hover') : $t->color('background'), $t->color('accent'), null],
            default => [$t->color('surface'), $t->color('text'), $t->color('border')],
        };
        if (!$enabled) {
            [$fill, $text] = [$t->color('track'), $t->color('disabled')];
        } elseif ($this->pressed) {
            $fill = in_array($this->props['style'], ['primary', 'danger'], true) ? $this->shade($fill, 0.82) : $t->color('pressed');
        } elseif ($this->hovered) {
            $fill = in_array($this->props['style'], ['primary', 'danger'], true) ? $this->shade($fill, 0.92) : ($this->props['style'] === 'flat' ? $fill : $t->color('hover'));
        }
        if ($this->props['background'] ?? false) {
            $fill = $this->tint($t, 'background', 'surface');
        }
        if ($this->focused) {
            $t->box($r, $this->x - $t->px(2), $this->y - $t->px(2), $this->w + $t->px(4), $this->h + $t->px(4), $t->color('focus')->withAlpha(110), $t->px($t->radius + 2));
        }
        $border !== null && $enabled ? $t->frame($r, $this->x, $this->y, $this->w, $this->h, $fill, $border) : $t->box($r, $this->x, $this->y, $this->w, $this->h, $fill);
        $font = $t->font($this->props['size'] ?? $t->fontSize, true);
        $label = $this->tr((string)$this->props['text']);
        $cx = $this->x + $this->w / 2;
        if ($this->props['image'] !== '') {
            $size = $t->px($t->fontSize * 1.3);
            $gap = $label === '' ? 0 : $t->px(6);
            $total = $size + $gap + $font->width($label);
            Picture::draw($r, $this->form, (string)$this->props['image'], $cx - $total / 2, $this->y + ($this->h - $size) / 2, $size, $size, 'contain');
            $cx += ($size + $gap) / 2;
        }
        $font->draw($r, $label, $cx, $this->y + ($this->h - $font->height()) / 2, 1, $this->props['color'] ?? null ? $this->tint($t, 'color', 'text') : $text, 'center');
    }

    private function shade(\JumPHP\Util\Color $c, float $f): \JumPHP\Util\Color
    {
        return new \JumPHP\Util\Color((int)($c->r * $f), (int)($c->g * $f), (int)($c->b * $f), $c->a);
    }

    public function keys(Pointer $p): bool
    {
        if ($p->pressed(Pointer::RETURN) || $p->pressed(Pointer::SPACE) || $p->pressed(Pointer::KP_ENTER)) {
            $this->emit('click');
            return true;
        }
        return false;
    }
}
