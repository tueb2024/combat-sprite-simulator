<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A list of lines to choose from: `items` (texts), `selected` (index, -1: none). Drag it (its bar, the wheel) to scroll,
 * arrows / Page up / Page down when it has the focus.
 * Events: change (int $index, string $item), activate (double click or Return on a line).
 *
 * @property list<string> $items
 * @property int $selected
 */
class ListBox extends Widget
{
    public const DEFAULTS = ['items' => [], 'selected' => -1, 'height' => 160];

    public float $scrollY = 0;
    private ?float $dragFrom = null;
    private float $dragged = 0;

    public static function events(): array
    {
        return ['change', 'activate', 'click'];
    }

    public function focusable(): bool
    {
        return $this->isEnabled();
    }

    /** The text of the chosen line, null when none. */
    public function value(): ?string
    {
        $items = array_values((array)$this->props['items']);
        return $items[(int)$this->props['selected']] ?? null;
    }

    public function addItem(string $item): void
    {
        $items = (array)$this->props['items'];
        $items[] = $item;
        $this->items = $items;
    }

    public function removeItem(int $index): void
    {
        $items = array_values((array)$this->props['items']);
        array_splice($items, $index, 1);
        $this->items = $items;
        if ((int)$this->props['selected'] >= count($items)) {
            $this->selected = count($items) - 1;
        }
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $font = $t->font();
        $w = $t->px(120);
        foreach ((array)$this->props['items'] as $item) {
            $w = max($w, $font->width((string)$item) + 2 * $t->px($t->padding));
        }
        return [$w, $this->rowHeight($t) * max(3, count((array)$this->props['items']))];
    }

    public function rowHeight(Theme $t): float
    {
        return $t->font()->lineHeight() + $t->px(10);
    }

    public function render(Renderer $r, Theme $t): void
    {
        $border = $this->focused ? $t->color('focus') : $t->color('border');
        $t->frame($r, $this->x, $this->y, $this->w, $this->h, $t->color('surface'), $border);
        $items = array_values((array)$this->props['items']);
        $rh = $this->rowHeight($t);
        $this->clampScroll($t);
        $font = $t->font();
        $b = max(1, round($t->scale));
        $first = (int)floor($this->scrollY / $rh);
        for ($i = $first; $i < count($items); $i++) {
            $y = $this->y + $b + $i * $rh - $this->scrollY;
            if ($y >= $this->y + $this->h - $b) {
                break;
            }
            $top = max($y, $this->y + $b);
            $bottom = min($y + $rh, $this->y + $this->h - $b);
            if ($i === (int)$this->props['selected']) {
                $r->fillRect($this->x + $b, $top, $this->w - 2 * $b, $bottom - $top, $t->color('selection'));
            }
            // a line cut by the edges: only its background (no clipping in SDL's binding)
            if ($y >= $this->y && $y + $rh <= $this->y + $this->h) {
                $font->draw($r, $this->fit($font, (string)$items[$i], $this->w - 2 * $t->px($t->padding)), $this->x + $t->px($t->padding),
                    $y + ($rh - $font->height()) / 2, 1, $this->isEnabled() ? $t->color('text') : $t->color('disabled'));
            }
        }
        $content = count($items) * $rh;
        if ($content > $this->h) {
            $barW = $t->px(4);
            $thumbH = max($t->px(20), $this->h * $this->h / $content);
            $thumbY = $this->y + ($this->h - $thumbH) * $this->scrollY / max(1, $content - $this->h);
            $t->box($r, $this->x + $this->w - $barW - $t->px(2), $thumbY, $barW, $thumbH, $t->color('border'), $barW / 2);
        }
    }

    /** A text cut with "…" to fit a width. */
    protected function fit(\JumPHP\Renderer\TextDrawer $font, string $text, float $width): string
    {
        if ($font->width($text) <= $width) {
            return $text;
        }
        while ($text !== '' && $font->width($text . '…') > $width) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '…';
    }

    private function clampScroll(Theme $t): void
    {
        $content = count((array)$this->props['items']) * $this->rowHeight($t);
        $this->scrollY = max(0, min($this->scrollY, $content - $this->h + 2));
    }

    public function wheel(float $notches): bool
    {
        if ($this->form === null) {
            return false;
        }
        $before = $this->scrollY;
        $this->scrollY -= $notches * 3 * $this->rowHeight($this->form->theme);
        $this->clampScroll($this->form->theme);
        return $this->scrollY !== $before;
    }

    public function select(int $index): void
    {
        $count = count((array)$this->props['items']);
        $index = max(-1, min($count - 1, $index));
        if ($index === (int)$this->props['selected']) {
            return;
        }
        $this->selected = $index;
        if ($this->form !== null && $index >= 0) {
            // the chosen line in sight
            $rh = $this->rowHeight($this->form->theme);
            $this->scrollY = max(min($this->scrollY, $index * $rh), ($index + 1) * $rh - $this->h + 2);
        }
        $this->emit('change', $index, (string)(array_values((array)$this->props['items'])[$index] ?? ''));
    }

    public function pointerDown(Pointer $p): void
    {
        $this->dragFrom = $p->y;
        $this->dragged = 0;
    }

    public function pointerDrag(Pointer $p): void
    {
        if ($this->dragFrom === null) {
            return;
        }
        $dy = $p->y - $this->dragFrom;
        $this->dragFrom = $p->y;
        $this->dragged += abs($dy);
        if ($this->dragged > 4 && $this->form !== null) {
            $this->scrollY -= $dy;
            $this->clampScroll($this->form->theme);
        }
    }

    public function pointerUp(Pointer $p, bool $inside): void
    {
        $this->dragFrom = null;
        if (!$inside || !$this->isEnabled() || $this->dragged > 4 || $this->form === null) {
            return;
        }
        $index = (int)floor(($p->y - $this->y + $this->scrollY) / $this->rowHeight($this->form->theme));
        if ($index >= 0 && $index < count((array)$this->props['items'])) {
            $this->select($index);
            if ($p->double) {
                $this->emit('activate', $index, (string)$this->value());
            }
        }
        $this->click($p);
    }

    public function keys(Pointer $p): bool
    {
        $page = $this->form !== null ? max(1, (int)floor($this->h / $this->rowHeight($this->form->theme)) - 1) : 5;
        $used = false;
        foreach ($p->keys as $key) {
            $index = (int)$this->props['selected'];
            $to = match ($key) {
                Pointer::UP => max(0, $index - 1),
                Pointer::DOWN => $index + 1,
                Pointer::PAGEUP => max(0, $index - $page),
                Pointer::PAGEDOWN => $index + $page,
                Pointer::HOME => 0,
                Pointer::END => count((array)$this->props['items']) - 1,
                default => null,
            };
            if ($to !== null) {
                $this->select($to);
                $used = true;
            } elseif (($key === Pointer::RETURN || $key === Pointer::KP_ENTER) && $index >= 0) {
                $this->emit('activate', $index, (string)$this->value());
                $used = true;
            }
        }
        return $used;
    }
}
