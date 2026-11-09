<?php

namespace JumPHP\Scene;

use JumPHP\Input\ActionMap;
use JumPHP\Kernel\Engine;
use JumPHP\Audio\SoundManager;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Renderer\TextDrawer;

/**
 * Base class of the game's scenes. Scenes are services: their constructor can ask for any service
 * (autowiring), and the engine is set before enter().
 *
 *   final class PlayScene extends AbstractScene
 *   {
 *       public function enter(array $params = []): void { $this->score = 0; }
 *       public function update(float $dt): void { if ($this->actions()->isPressed('pause')) $this->scenes()->push(PauseScene::class); }
 *       public function render(Renderer $r): void { $this->font()->draw($r, "SCORE {$this->score}", 4, 4); }
 *   }
 */
abstract class AbstractScene implements Scene
{
    protected Engine $engine;

    public function setEngine(Engine $engine): void
    {
        $this->engine = $engine;
    }

    public function enter(array $params = []): void
    {
    }

    public function exit(): void
    {
    }

    public function pause(): void
    {
    }

    public function resume(mixed $result = null): void
    {
    }

    public function isOverlay(): bool
    {
        return false;
    }

    abstract public function update(float $dt): void;

    abstract public function render(Renderer $r): void;

    protected function actions(): ActionMap
    {
        return $this->engine->actions;
    }

    protected function scenes(): SceneManager
    {
        return $this->engine->scenes;
    }

    protected function sound(): SoundManager
    {
        return $this->engine->sound;
    }

    protected function sdl(): Sdl
    {
        return $this->engine->sdl;
    }

    protected function font(): TextDrawer
    {
        return $this->engine->font();
    }

    /** Width of the view (virtual resolution). */
    protected function width(): int
    {
        return $this->engine->sdl->getRenderer()->getWidth();
    }

    protected function height(): int
    {
        return $this->engine->sdl->getRenderer()->getHeight();
    }
}
