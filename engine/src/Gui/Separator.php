<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/** A thin line between two groups: across a column, or down a row. */
class Separator extends Widget
{
    public static function events(): array
    {
        return [];
    }

    private function vertical(): bool
    {
        return $this->parent !== null && $this->parent->props['layout'] === 'row';
    }

    public function stretches(string $axis): bool
    {
        return true;
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $line = max(1, round($t->scale));
        return $this->vertical() ? [$line, $t->px(24)] : [$t->px(24), $line];
    }

    public function render(Renderer $r, Theme $t): void
    {
        $line = max(1, round($t->scale));
        $this->vertical()
            ? $r->fillRect($this->x + ($this->w - $line) / 2, $this->y, $line, $this->h, $t->color('border'))
            : $r->fillRect($this->x, $this->y + ($this->h - $line) / 2, $this->w, $line, $t->color('border'));
    }
}
