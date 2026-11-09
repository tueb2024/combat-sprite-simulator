<?php

namespace JumPHP\Tests\Debug;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;
use JumPHP\Scene\HotReloadable;

/** The scene of HotReloadTest: counts its entries, keeps its score through a hot reload. */
final class HotScene extends AbstractScene implements HotReloadable
{
    public int $entered = 0;
    public int $score = 0;
    public array $params = [];

    public function enter(array $params = []): void
    {
        $this->entered++;
        $this->score = 0;
        $this->params = $params;
    }

    public function update(float $dt): void
    {
    }

    public function render(Renderer $r): void
    {
    }

    public function hotState(): array
    {
        return ['score' => $this->score];
    }

    public function hotRestore(array $state): void
    {
        $this->score = (int)($state['score'] ?? 0);
    }
}
