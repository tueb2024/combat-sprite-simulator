<?php

namespace JumPHP\Ui;

use JumPHP\Input\ActionMap;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;
use JumPHP\Util\Lang;

/**
 * A vertical menu. Each item is a row [label, value, onPress, onSide]:
 *   - label:   string (translated with Lang::t) or callable(): string
 *   - value:   null, string or callable(): string, drawn after the label ("VOLUME  < 80% >")
 *   - onPress: callable(): void, on confirm (null = not selectable, e.g. a separator)
 *   - onSide:  callable(int $dir): void, on left (-1) / right (+1): change a value in place
 *
 *   $menu = new Menu([
 *       ['1 PLAYER', null, fn () => $scenes->switchTo(PlayScene::class)],
 *       ['VOLUME', fn () => round($vol * 100) . '%', null, fn (int $d) => $vol = max(0, min(1, $vol + $d / 10))],
 *       ['QUIT', null, fn () => $engine->quit()],
 *   ]);
 *   $menu->update($actions);  $menu->render($r, $font, $r->getWidth() / 2, 80);
 *
 * Actions used: up, down, left, right, confirm, back (the engine's default input.yaml binds them).
 */
class Menu
{
    public int $selected = 0;
    /** @var callable|null on back (Escape) */
    public $onBack = null;
    /** @var callable|null when the selection moves or a value changes (sound) */
    public $onMove = null;
    /** @var callable|null when an item is pressed (sound) */
    public $onConfirm = null;

    public array $color = [200, 200, 200];
    public array $selectedColor = [255, 255, 80];
    public array $disabledColor = [110, 110, 110];
    public ?array $shadow = [0, 0, 0];
    public float $scale = 1.0;
    public int $spacing = 4;
    public string $cursor = '>';

    /** @param array<int, array> $items */
    public function __construct(private array $items = [])
    {
        $this->selected = $this->firstSelectable(0, 1);
    }

    /** @param array<int, array> $items replaces the items, keeps the selection when possible */
    public function setItems(array $items): void
    {
        $this->items = array_values($items);
        if (!$this->isSelectable($this->selected)) {
            $this->selected = $this->firstSelectable(0, 1);
        }
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function update(ActionMap $actions): void
    {
        if ($actions->isPressed('down')) {
            $this->move(1);
        } elseif ($actions->isPressed('up')) {
            $this->move(-1);
        } elseif ($actions->isPressed('left') || $actions->isPressed('right')) {
            $onSide = $this->items[$this->selected][3] ?? null;
            if ($onSide !== null) {
                $onSide($actions->isPressed('left') ? -1 : 1);
                $this->onMove && ($this->onMove)();
            }
        } elseif ($actions->isPressed('confirm')) {
            $item = $this->items[$this->selected] ?? null;
            if ($item !== null && isset($item[2])) {
                $this->onConfirm && ($this->onConfirm)();
                ($item[2])();
            } elseif ($item !== null && isset($item[3])) {
                ($item[3])(1); // confirm on a value cycles it
                $this->onMove && ($this->onMove)();
            }
        } elseif ($actions->isPressed('back') && $this->onBack !== null) {
            ($this->onBack)();
        }
    }

    public function move(int $dir): void
    {
        $next = $this->firstSelectable($this->selected + $dir, $dir);
        if ($next !== $this->selected) {
            $this->selected = $next;
            $this->onMove && ($this->onMove)();
        }
    }

    /** Draws the menu centred on $x, from $y down. Returns the height used. */
    public function render(Renderer $r, TextDrawer $font, float $x, float $y): int
    {
        $line = $font->lineHeight($this->scale) + $this->spacing;
        foreach ($this->items as $i => $item) {
            $text = $this->text($item);
            $color = !$this->isSelectable($i) ? $this->disabledColor : ($i === $this->selected ? $this->selectedColor : $this->color);
            if ($i === $this->selected && $this->isSelectable($i)) {
                $hasValue = isset($item[3]);
                $text = $hasValue ? '< ' . $text . ' >' : $this->cursor . ' ' . $text . ' ' . str_repeat(' ', strlen($this->cursor));
            }
            $font->draw($r, $text, $x, $y + $i * $line, $this->scale, $color, 'center', $this->shadow);
        }
        return count($this->items) * $line;
    }

    public function text(array $item): string
    {
        $label = is_callable($item[0]) ? ($item[0])() : Lang::t((string)$item[0]);
        $value = $item[1] ?? null;
        if (is_callable($value)) {
            $value = $value();
        }
        return $value === null || $value === '' ? $label : $label . '  ' . $value;
    }

    private function isSelectable(int $i): bool
    {
        return isset($this->items[$i]) && (isset($this->items[$i][2]) || isset($this->items[$i][3]));
    }

    private function firstSelectable(int $from, int $dir): int
    {
        $n = count($this->items);
        for ($k = 0; $k < $n; $k++) {
            $i = (($from + $k * $dir) % $n + $n) % $n;
            if ($this->isSelectable($i)) {
                return $i;
            }
        }
        return 0;
    }
}
