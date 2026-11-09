<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * Empty room between two widgets: `size` dp, or (no size) all the free room of the row / column, shared with the
 * other spacers (two spacers around a button centre it; one before it pushes it to the end).
 *
 * @property float $size
 */
class Spacer extends Widget
{
    public const DEFAULTS = ['size' => 0];

    public function __construct(array $props = [])
    {
        if (!isset($props['grow']) && empty($props['size'])) {
            $props['grow'] = 1;
        }
        parent::__construct($props);
    }

    public static function events(): array
    {
        return [];
    }

    public function stretches(string $axis): bool
    {
        return false;
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $s = $t->px((float)$this->props['size']);
        return [$s, $s];
    }

    public function render(Renderer $r, Theme $t): void
    {
        $this->form?->designing && $r->drawRect($this->x, $this->y, max(1, $this->w), max(1, $this->h), $t->color('border'));
    }
}
