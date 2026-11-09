<?php

namespace JumPHP\Scene;

use JumPHP\Kernel\Engine;
use JumPHP\Renderer\Renderer;

/**
 * A stack of scenes. The top one is updated; it and the overlays under it are drawn.
 *
 *   $scenes->switchTo(PlayScene::class, ['mode' => 'cpu']);  // replaces everything (fade)
 *   $scenes->push(PauseScene::class);                        // over the current one (it is paused)
 *   $scenes->pop($result);                                   // back to the one below (resume($result))
 *
 * Scene classes are resolved through the service container (constructor autowiring).
 */
class SceneManager
{
    /** @var Scene[] bottom first */
    private array $stack = [];
    /** @var callable(string): Scene */
    private $resolver;
    private ?Engine $engine = null;

    private ?array $pending = null;   // [op, scene|null, params, fade]
    private float $fade = 0.0;        // 0 = clear, 1 = black
    private int $fadeDirection = 0;   // 1 = going dark, -1 = coming back
    private float $fadeSpeed = 4.0;   // 1 / seconds per half
    /** @var \WeakMap<Scene, array> the parameters each scene was entered with (hot reload: entered again) */
    private \WeakMap $params;

    public function __construct()
    {
        $this->resolver = static fn (string $class): Scene => new $class();
        $this->params = new \WeakMap();
    }

    /** The parameters $scene was entered with. */
    public function paramsOf(Scene $scene): array
    {
        return $this->params[$scene] ?? [];
    }

    public function setEngine(Engine $engine): void
    {
        $this->engine = $engine;
    }

    /** @param callable(string): Scene $resolver */
    public function setResolver(callable $resolver): void
    {
        $this->resolver = $resolver;
    }

    public function setFadeDuration(float $seconds): void
    {
        $this->fadeSpeed = $seconds > 0 ? 1 / $seconds : 1000.0;
    }

    public function switchTo(string|Scene $scene, array $params = [], bool $fade = true): void
    {
        $this->request('switch', $scene, $params, $fade);
    }

    public function push(string|Scene $scene, array $params = [], bool $fade = false): void
    {
        $this->request('push', $scene, $params, $fade);
    }

    public function pop(mixed $result = null, bool $fade = false): void
    {
        $this->request('pop', null, ['result' => $result], $fade);
    }

    public function current(): ?Scene
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    /** @return Scene[] */
    public function stack(): array
    {
        return $this->stack;
    }

    public function isTransitioning(): bool
    {
        return $this->pending !== null || $this->fadeDirection !== 0;
    }

    public function update(float $dt): void
    {
        if ($this->fadeDirection !== 0) {
            $this->fade += $this->fadeDirection * $this->fadeSpeed * min($dt, 0.05);
            if ($this->fadeDirection > 0 && $this->fade >= 1) {
                $this->fade = 1;
                $this->apply();
                $this->fadeDirection = -1;
            } elseif ($this->fadeDirection < 0 && $this->fade <= 0) {
                $this->fade = 0;
                $this->fadeDirection = 0;
            }
            if ($this->fadeDirection > 0) {
                return; // going dark: the old scene is frozen
            }
        } elseif ($this->pending !== null) {
            $this->apply();
        }

        $this->current()?->update($dt);
    }

    public function render(Renderer $r): void
    {
        $first = count($this->stack) - 1;
        while ($first > 0 && $this->stack[$first]->isOverlay()) {
            $first--;
        }
        for ($i = max(0, $first), $n = count($this->stack); $i < $n; $i++) {
            $this->stack[$i]->render($r);
        }
        if ($this->fade > 0) {
            $r->fillRect(0, 0, $r->getWidth(), $r->getHeight(), [0, 0, 0, (int)round($this->fade * 255)]);
        }
    }

    private function request(string $op, string|Scene|null $scene, array $params, bool $fade): void
    {
        $this->pending = [$op, $scene, $params];
        if ($fade && $this->stack !== []) {
            $this->fadeDirection = 1;
        } else {
            $this->apply();
        }
    }

    /** Runs the pending operation now. */
    private function apply(): void
    {
        if ($this->pending === null) {
            return;
        }
        [$op, $scene, $params] = $this->pending;
        $this->pending = null;

        if ($op === 'pop') {
            array_pop($this->stack)?->exit();
            $this->current()?->resume($params['result'] ?? null);
            return;
        }

        $scene = $scene instanceof Scene ? $scene : ($this->resolver)($scene);
        if ($this->engine !== null) {
            $scene->setEngine($this->engine);
        }
        if ($op === 'switch') {
            while ($this->stack !== []) {
                array_pop($this->stack)->exit();
            }
        } else {
            $this->current()?->pause();
        }
        $this->stack[] = $scene;
        $this->params[$scene] = $params;
        $scene->enter($params);
    }
}
