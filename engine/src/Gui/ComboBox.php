<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A drop-down list: `items`, `selected` (index, -1: none), `placeholder`. A click opens the list over the form; arrows
 * change the choice when it has the focus.
 * Event: change (int $index, string $item).
 *
 * @property list<string> $items
 * @property int $selected
 */
class ComboBox extends Widget implements Overlay
{
    public const DEFAULTS = ['items' => [], 'selected' => -1, 'placeholder' => 'Choose…', 'rows' => 8];

    public bool $open = false;
    private int $hover = -1;
    private int $first = 0;

    public static function events(): array
    {
        return ['change', 'click'];
    }

    public function focusable(): bool
    {
        return $this->isEnabled();
    }

    public function value(): ?string
    {
        $items = array_values((array)$this->props['items']);
        return $items[(int)$this->props['selected']] ?? null;
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $font = $t->font();
        $w = $font->width($this->tr((string)$this->props['placeholder']));
        foreach ((array)$this->props['items'] as $item) {
            $w = max($w, $font->width((string)$item));
        }
        return [$w + 2 * $t->px($t->padding) + $t->px(24), $t->px($t->controlHeight)];
    }

    public function render(Renderer $r, Theme $t): void
    {
        $border = $this->focused || $this->open ? $t->color('focus') : ($this->hovered ? $t->color('muted') : $t->color('border'));
        $t->frame($r, $this->x, $this->y, $this->w, $this->h, $this->isEnabled() ? $t->color('surface') : $t->color('track'), $border);
        $font = $t->font();
        $value = $this->value();
        $pad = $t->px($t->padding);
        $font->draw($r, $value ?? $this->tr((string)$this->props['placeholder']), $this->x + $pad, $this->y + ($this->h - $font->height()) / 2, 1,
            $value === null || !$this->isEnabled() ? $t->color('muted') : $t->color('text'));
        // the arrow
        $ax = $this->x + $this->w - $pad - $t->px(6);
        $ay = $this->y + $this->h / 2 - $t->px(2);
        for ($i = 0, $n = (int)round($t->px(5)); $i < $n; $i++) {
            $r->fillRect($ax - $n + $i + 1, $ay + $i, 2 * ($n - $i) - 1, 1, $t->color('muted'));
        }
    }

    /** @return array{0: float, 1: float, 2: float, 3: float, 4: int} the open list: x, y, w, h, rows shown */
    private function popup(Theme $t): array
    {
        $count = count((array)$this->props['items']);
        $rows = max(1, min($count, (int)$this->props['rows']));
        $rh = $t->px($t->controlHeight * 0.9);
        $h = $rows * $rh + $t->px(8);
        $viewH = $this->form?->viewHeight() ?? 10000;
        $offset = $this->parent?->scrollOffset() ?? 0;
        $y = $this->y + $this->h + $t->px(4);
        if ($y - $offset + $h > $viewH && $this->y - $offset - $h - $t->px(4) >= 0) {
            $y = $this->y - $h - $t->px(4);       // no room below: above
        }
        return [$this->x, $y, $this->w, $h, $rows];
    }

    public function renderOverlay(Renderer $r, Theme $t): void
    {
        if (!$this->open) {
            return;
        }
        [$x, $y, $w, $h, $rows] = $this->popup($t);
        $r->fillRect($x + $t->px(2), $y + $t->px(3), $w, $h, [0, 0, 0, 40]);
        $t->frame($r, $x, $y, $w, $h, $t->color('surface'), $t->color('border'));
        $items = array_values((array)$this->props['items']);
        $rh = $t->px($t->controlHeight * 0.9);
        $font = $t->font();
        for ($n = 0; $n < $rows; $n++) {
            $i = $this->first + $n;
            if (!isset($items[$i])) {
                break;
            }
            $ry = $y + $t->px(4) + $n * $rh;
            if ($i === $this->hover || ($this->hover < 0 && $i === (int)$this->props['selected'])) {
                $r->fillRect($x + $t->px(4), $ry, $w - $t->px(8), $rh, $i === (int)$this->props['selected'] ? $t->color('selection') : $t->color('hover'));
            }
            $font->draw($r, (string)$items[$i], $x + $t->px($t->padding), $ry + ($rh - $font->height()) / 2, 1, $t->color('text'));
        }
        if (count($items) > $rows) {
            $barH = $h * $rows / count($items);
            $barY = $y + ($h - $barH) * $this->first / max(1, count($items) - $rows);
            $t->box($r, $x + $w - $t->px(6), $barY, $t->px(3), $barH, $t->color('border'), $t->px(1.5));
        }
    }

    public function overlayContains(float $px, float $py): bool
    {
        if (!$this->open || $this->form === null) {
            return false;
        }
        [$x, $y, $w, $h] = $this->popup($this->form->theme);
        return $px >= $x && $px < $x + $w && $py >= $y && $py < $y + $h;
    }

    /** The line of the open list under a point, -1 when none. */
    private function rowAt(float $py): int
    {
        if ($this->form === null) {
            return -1;
        }
        $t = $this->form->theme;
        [, $y, , , $rows] = $this->popup($t);
        $n = (int)floor(($py - $y - $t->px(4)) / $t->px($t->controlHeight * 0.9));
        return $n >= 0 && $n < $rows && $this->first + $n < count((array)$this->props['items']) ? $this->first + $n : -1;
    }

    public function openList(): void
    {
        $this->open = true;
        $rows = (int)$this->props['rows'];
        $count = count((array)$this->props['items']);
        $this->first = max(0, min((int)$this->props['selected'] - intdiv($rows, 2), $count - $rows));
        $this->hover = -1;
        $this->form?->setOverlay($this);
    }

    /** The open list scrolls (a line per notch); closed, the wheel goes to the page. */
    public function wheel(float $notches): bool
    {
        if (!$this->open || $this->form === null) {
            return false;
        }
        $rows = $this->popup($this->form->theme)[4];
        $this->first = max(0, min($this->first - (int)round($notches), count((array)$this->props['items']) - $rows));
        return true;
    }

    public function close(): void
    {
        $this->open = false;
        if ($this->form?->overlay === $this) {
            $this->form->setOverlay(null);
        }
    }

    public function select(int $index): void
    {
        $index = max(-1, min(count((array)$this->props['items']) - 1, $index));
        if ($index !== (int)$this->props['selected']) {
            $this->selected = $index;
            $this->emit('change', $index, (string)$this->value());
        }
    }

    public function pointerUp(Pointer $p, bool $inside): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        if ($this->open && $this->overlayContains($p->x, $p->y)) {
            $row = $this->rowAt($p->y);
            if ($row >= 0) {
                $this->select($row);
            }
            $this->close();
            return;
        }
        if ($inside) {
            $this->open ? $this->close() : $this->openList();
            $this->click($p);
        } else {
            $this->close();
        }
    }

    public function pointerDrag(Pointer $p): void
    {
        if ($this->open) {
            $this->hover = $this->rowAt($p->y);
        }
    }

    /** The pointer moves over the open list. */
    public function hoverAt(float $py): void
    {
        $this->hover = $this->rowAt($py);
    }

    public function keys(Pointer $p): bool
    {
        $used = false;
        $count = count((array)$this->props['items']);
        foreach ($p->keys as $key) {
            if ($key === Pointer::UP || $key === Pointer::DOWN) {
                $this->select(max(0, min($count - 1, (int)$this->props['selected'] + ($key === Pointer::UP ? -1 : 1))));
                $rows = (int)$this->props['rows'];
                $this->first = max(0, min($this->first, (int)$this->props['selected']), (int)$this->props['selected'] - $rows + 1);
                $used = true;
            } elseif (in_array($key, [Pointer::RETURN, Pointer::KP_ENTER, Pointer::SPACE], true)) {
                $this->open ? $this->close() : $this->openList();
                $used = true;
            } elseif ($key === Pointer::ESCAPE && $this->open) {
                $this->close();
                $used = true;
            }
        }
        return $used;
    }

    public function blur(): void
    {
        $this->close();
    }
}
