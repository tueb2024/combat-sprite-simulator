<?php

namespace JumPHP\Ui;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * A ready-made menu screen: a title and a Menu, centred.
 *
 *   final class TitleScene extends MenuScene
 *   {
 *       protected string $title = 'MY GAME';
 *       protected function items(): array
 *       {
 *           return [
 *               ['PLAY', null, fn () => $this->scenes()->switchTo(PlayScene::class)],
 *               ['QUIT', null, fn () => $this->engine->quit()],
 *           ];
 *       }
 *   }
 *
 * Plays the sounds 'menu_move' and 'menu_confirm' when the game defined them. Override
 * renderBackground() for a background, refresh() after changing a value shown by the items.
 */
abstract class MenuScene extends AbstractScene
{
    protected Menu $menu;
    protected string $title = '';
    protected float $titleScale = 3.0;
    protected array $titleColor = [255, 255, 255];
    protected array $background = [10, 10, 30];
    /** Where the menu starts, as a fraction of the view height. */
    protected float $menuTop = 0.45;

    /** @return array<int, array> rows [label, value, onPress, onSide] (see Menu) */
    abstract protected function items(): array;

    public function enter(array $params = []): void
    {
        $this->menu = new Menu($this->items());
        $this->menu->onMove = fn () => $this->sound()->playSfx('menu_move');
        $this->menu->onConfirm = fn () => $this->sound()->playSfx('menu_confirm');
        $this->menu->onBack = fn () => $this->back();
    }

    /** Escape on this menu. Default: nothing (title screen); sub-menus pop or switch back. */
    protected function back(): void
    {
    }

    public function refresh(): void
    {
        $this->menu->setItems($this->items());
    }

    public function update(float $dt): void
    {
        $this->menu->update($this->actions());
    }

    public function render(Renderer $r): void
    {
        $this->renderBackground($r);
        $w = $this->width();
        if ($this->title !== '') {
            $this->font()->draw($r, \JumPHP\Util\Lang::t($this->title), $w / 2, $this->height() * 0.15, $this->titleScale, $this->titleColor, 'center', [0, 0, 0]);
        }
        $this->menu->render($r, $this->font(), $w / 2, $this->height() * $this->menuTop);
    }

    protected function renderBackground(Renderer $r): void
    {
        if (!$this->isOverlay()) {
            $r->clear($this->background);
        } else {
            $r->fillRect(0, 0, $this->width(), $this->height(), [0, 0, 0, 170]);
        }
    }
}
