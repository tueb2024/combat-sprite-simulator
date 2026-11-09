<?php

namespace JumPHP\Gui;

use JumPHP\Renderer\Renderer;

/**
 * A small menu opened by a right click, drawn over the form (Overlay): Cut / Copy / Paste / Select all of a text field. The widget says which
 * rows it has (menuItems: [id, enabled]) and what a row does (runMenu); the form opens it (openMenu) and gives it the pointer.
 */
trait HasContextMenu
{
    private bool $menuOpen = false;
    private float $menuX = 0;
    private float $menuY = 0;
    private int $menuHover = -1;

    /** @return list<array{0: string, 1: bool}> the rows: their id, whether they can be used now */
    abstract protected function menuItems(): array;

    abstract protected function runMenu(string $id): void;

    abstract protected static function menuLabel(string $id): string;

    public function openMenu(float $x, float $y): void
    {
        $this->menuOpen = true;
        $this->menuX = $x;
        $this->menuY = $y;
        $this->menuHover = -1;
        $this->form?->setOverlay($this);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float, 4: float} x, y, w, h, a row's height, kept inside the view */
    private function menuBox(Theme $t): array
    {
        $font = $t->font();
        $rh = $t->px($t->controlHeight * 0.9);
        $w = 0.0;
        foreach ($this->menuItems() as [$id]) {
            $w = max($w, $font->width(self::menuLabel($id)));
        }
        $w += 2 * $t->px($t->padding) + $t->px(16);
        $h = count($this->menuItems()) * $rh + $t->px(8);
        $x = min($this->menuX, ($this->form?->viewWidth() ?? 10000) - $w - 2);
        $y = min($this->menuY, ($this->form?->viewHeight() ?? 10000) - $h - 2);
        return [max(0.0, $x), max(0.0, $y), $w, $h, $rh];
    }

    private function menuRow(float $py): int
    {
        if ($this->form === null) {
            return -1;
        }
        $t = $this->form->theme;
        [, $y, , , $rh] = $this->menuBox($t);
        $n = (int)floor(($py - $y - $t->px(4)) / $rh);
        return $n >= 0 && $n < count($this->menuItems()) ? $n : -1;
    }

    /** The button went up while the menu is open: a row is run, anywhere closes it. Always true (the click was the menu's). */
    private function menuClick(Pointer $p): bool
    {
        if ($this->overlayContains($p->x, $p->y)) {
            $row = $this->menuRow($p->y);
            $items = $this->menuItems();
            if ($row >= 0 && $items[$row][1]) {
                $this->runMenu($items[$row][0]);
            }
        }
        $this->close();
        return true;
    }

    public function renderOverlay(Renderer $r, Theme $t): void
    {
        if (!$this->menuOpen) {
            return;
        }
        [$x, $y, $w, $h, $rh] = $this->menuBox($t);
        $r->fillRect($x + $t->px(2), $y + $t->px(3), $w, $h, [0, 0, 0, 40]);
        $t->frame($r, $x, $y, $w, $h, $t->color('surface'), $t->color('border'));
        $font = $t->font();
        foreach ($this->menuItems() as $n => [$id, $enabled]) {
            $ry = $y + $t->px(4) + $n * $rh;
            if ($n === $this->menuHover && $enabled) {
                $r->fillRect($x + $t->px(4), $ry, $w - $t->px(8), $rh, $t->color('hover'));
            }
            $font->draw($r, self::menuLabel($id), $x + $t->px($t->padding), $ry + ($rh - $font->height()) / 2, 1, $enabled ? $t->color('text') : $t->color('disabled'));
        }
    }

    public function overlayContains(float $px, float $py): bool
    {
        if (!$this->menuOpen || $this->form === null) {
            return false;
        }
        [$x, $y, $w, $h] = $this->menuBox($this->form->theme);
        return $px >= $x && $px < $x + $w && $py >= $y && $py < $y + $h;
    }

    public function hoverAt(float $py): void
    {
        $this->menuHover = $this->menuRow($py);
    }

    public function close(): void
    {
        $this->menuOpen = false;
        if ($this->form?->overlay === $this) {
            $this->form->setOverlay(null);
        }
    }
}
