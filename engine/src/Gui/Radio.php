<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * One choice among several: the radios of the same `group` in the form (default: the same container) are exclusive.
 * Event: change (true when it gets chosen).
 *
 * @property string $group
 */
class Radio extends CheckBox
{
    public const DEFAULTS = ['text' => 'Choice', 'checked' => false, 'group' => ''];

    protected function mark(Renderer $r, Theme $t, float $x, float $y, float $s): void
    {
        $on = (bool)$this->props['checked'];
        $border = $on ? $t->color($this->isEnabled() ? 'accent' : 'disabled') : ($this->hovered || $this->focused ? $t->color('muted') : $t->color('border'));
        $r->fillCircle($x + $s / 2, $y + $s / 2, $s / 2, $border);
        $r->fillCircle($x + $s / 2, $y + $s / 2, $s / 2 - max(1, $t->px(1.5)), $t->color('surface'));
        if ($on) {
            $r->fillCircle($x + $s / 2, $y + $s / 2, $s / 4, $border);
        }
    }

    protected function toggle(): void
    {
        if ($this->props['checked']) {
            return;
        }
        foreach ($this->siblings() as $other) {
            if ($other !== $this && $other->props['checked']) {
                $other->checked = false;
                $other->emit('change', false);
            }
        }
        $this->checked = true;
        $this->emit('change', true);
    }

    /** @return list<Radio> the radios of its group */
    public function siblings(): array
    {
        $group = (string)$this->props['group'];
        $pool = $group !== '' && $this->form?->root !== null ? iterator_to_array($this->form->root->walk(), false) : ($this->parent !== null ? $this->parent->children : [$this]);
        return array_values(array_filter($pool, fn (Widget $w) => $w instanceof Radio && (string)$w->props['group'] === $group
            && ($group !== '' || $w->parent === $this->parent)));
    }
}
