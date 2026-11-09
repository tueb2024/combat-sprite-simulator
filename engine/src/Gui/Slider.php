<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A value chosen by sliding: `value`, `min`, `max`, `step` (0: any). Arrows when it has the focus.
 * Event: change (float $value).
 *
 * @property float $value
 */
class Slider extends Widget
{
    public const DEFAULTS = ['value' => 50, 'min' => 0, 'max' => 100, 'step' => 1];

    public static function events(): array
    {
        return ['change'];
    }

    public function focusable(): bool
    {
        return $this->isEnabled();
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        return [$t->px(160), $t->px(max(24, $t->controlHeight * 0.75))];
    }

    private function ratio(): float
    {
        $min = (float)$this->props['min'];
        $max = (float)$this->props['max'];
        return $max > $min ? max(0, min(1, ((float)$this->props['value'] - $min) / ($max - $min))) : 0;
    }

    public function render(Renderer $r, Theme $t): void
    {
        $knob = $t->px(9);
        $trackH = $t->px(4);
        $x0 = $this->x + $knob;
        $len = $this->w - 2 * $knob;
        $cy = $this->y + $this->h / 2;
        $t->box($r, $x0, $cy - $trackH / 2, $len, $trackH, $t->color('track'), $trackH / 2);
        $kx = $x0 + $len * $this->ratio();
        $accent = $t->color($this->isEnabled() ? 'accent' : 'disabled');
        $t->box($r, $x0, $cy - $trackH / 2, $kx - $x0, $trackH, $accent, $trackH / 2);
        if ($this->focused) {
            $r->fillCircle($kx, $cy, $knob + $t->px(3), $t->color('focus')->withAlpha(70));
        }
        $r->fillCircle($kx, $cy, $knob, $accent);
        $r->fillCircle($kx, $cy, $knob - $t->px(3), $t->color('surface'));
    }

    public function setValue(float $value): void
    {
        $min = (float)$this->props['min'];
        $max = (float)$this->props['max'];
        $step = (float)$this->props['step'];
        if ($step > 0) {
            $value = $min + round(($value - $min) / $step) * $step;
        }
        $value = max($min, min($max, $value));
        if ($value != $this->props['value']) {
            $this->props['value'] = $value;
            $this->emit('change', $value);
        }
    }

    private function fromPointer(Pointer $p): void
    {
        $knob = $this->form?->theme->px(9) ?? 9;
        $ratio = max(0, min(1, ($p->x - $this->x - $knob) / max(1, $this->w - 2 * $knob)));
        $this->setValue((float)$this->props['min'] + $ratio * ((float)$this->props['max'] - (float)$this->props['min']));
    }

    public function pointerDown(Pointer $p): void
    {
        if ($this->isEnabled()) {
            $this->fromPointer($p);
        }
    }

    public function pointerDrag(Pointer $p): void
    {
        if ($this->isEnabled()) {
            $this->fromPointer($p);
        }
    }

    public function keys(Pointer $p): bool
    {
        $step = (float)$this->props['step'] ?: ((float)$this->props['max'] - (float)$this->props['min']) / 100;
        $used = false;
        foreach ($p->keys as $key) {
            if ($key === Pointer::LEFT || $key === Pointer::DOWN) {
                $this->setValue((float)$this->props['value'] - $step);
                $used = true;
            } elseif ($key === Pointer::RIGHT || $key === Pointer::UP) {
                $this->setValue((float)$this->props['value'] + $step);
                $used = true;
            }
        }
        return $used;
    }
}
