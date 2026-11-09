<?php

namespace App\Scene;

use JumPHP\Ui\MenuScene;

/**
 * Pause menu, pushed over the game: isOverlay() keeps the frozen game visible under it.
 */
final class PauseScene extends MenuScene
{
    protected string $title = 'PAUSE';
    protected float $titleScale = 2.0;
    protected float $menuTop = 0.5;

    public function isOverlay(): bool
    {
        return true;
    }

    protected function items(): array
    {
        return [
            ['RESUME', null, fn () => $this->scenes()->pop()],
            ['TITLE SCREEN', null, fn () => $this->scenes()->switchTo(TitleScene::class)],
        ];
    }

    protected function back(): void
    {
        $this->scenes()->pop();
    }

    public function update(float $dt): void
    {
        if ($this->actions()->isPressed('pause') && !$this->actions()->isPressed('back')) {
            $this->scenes()->pop();
            return;
        }
        parent::update($dt);
    }
}
