<?php

namespace JumPHP\Scene;

use JumPHP\Kernel\Engine;
use JumPHP\Renderer\Renderer;

/**
 * A screen of the game: title, menu, level, pause... The SceneManager runs the scene on top of its stack.
 * Extend AbstractScene rather than implementing this directly.
 */
interface Scene
{
    public function setEngine(Engine $engine): void;

    /** The scene becomes active (switchTo / push). Reset its state here: instances are reused. */
    public function enter(array $params = []): void;

    /** The scene is removed (switchTo another one / pop). */
    public function exit(): void;

    /** Another scene was pushed over this one. */
    public function pause(): void;

    /** The scene over this one was popped; $result is what it passed to pop(). */
    public function resume(mixed $result = null): void;

    /** Game logic, $dt in seconds (variable: use FixedStep for physics that must be steady). */
    public function update(float $dt): void;

    /** Draw at the virtual resolution. */
    public function render(Renderer $r): void;

    /** true: the scene below is drawn too (pause menu over the frozen game). */
    public function isOverlay(): bool;
}
