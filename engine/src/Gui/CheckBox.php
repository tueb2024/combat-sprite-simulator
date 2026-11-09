<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A box to tick: `text`, `checked`. Event: change (bool $checked).
 *
 * @property string $text
 * @property bool $checked
 */
class CheckBox extends Widget
{
    public const DEFAULTS = ['text' => 'Option', 'checked' => false];

    public static function events(): array
    {
        return ['change', 'click'];
    }

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
        return [$t->px(20) + $t->px(8) + $t->font()->width($this->tr((string)$this->props['text'])), $t->px(max(24, $t->controlHeight * 0.75))];
    }

    protected function mark(Renderer $r, Theme $t, float $x, float $y, float $s): void
    {
        $on = (bool)$this->props['checked'];
        $border = $this->focused ? $t->color('focus') : ($this->hovered ? $t->color('muted') : $t->color('border'));
        $t->frame($r, $x, $y, $s, $s, $on ? $t->color($this->isEnabled() ? 'accent' : 'disabled') : $t->color('surface'),
            $on ? $t->color($this->isEnabled() ? 'accent' : 'disabled') : $border, $t->px(4));
        if ($on) {
            // the tick: two thick strokes
            $c = $t->color('accentText');
            $w = max(1, (int)round($t->px(2)));
            for ($i = 0; $i < $w; $i++) {
                $r->drawLine($x + $s * 0.22, $y + $s * 0.52 + $i, $x + $s * 0.42, $y + $s * 0.72 + $i, $c);
                $r->drawLine($x + $s * 0.42, $y + $s * 0.72 + $i, $x + $s * 0.78, $y + $s * 0.32 + $i, $c);
            }
        }
    }

    public function render(Renderer $r, Theme $t): void
    {
        $s = $t->px(20);
        $this->mark($r, $t, $this->x, $this->y + ($this->h - $s) / 2, $s);
        $font = $t->font();
        $font->draw($r, $this->tr((string)$this->props['text']), $this->x + $s + $t->px(8), $this->y + ($this->h - $font->height()) / 2, 1,
            $this->isEnabled() ? $this->tint($t, 'color', 'text') : $t->color('disabled'));
    }

    protected function click(Pointer $p): void
    {
        $this->toggle();
        parent::click($p);
    }

    protected function toggle(): void
    {
        $this->checked = !$this->props['checked'];
        $this->emit('change', $this->props['checked']);
    }

    public function keys(Pointer $p): bool
    {
        if ($p->pressed(Pointer::SPACE)) {
            $this->toggle();
            return true;
        }
        return false;
    }
}
