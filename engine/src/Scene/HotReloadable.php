<?php

namespace JumPHP\Scene;

/**
 * A scene that keeps its state when the game is hot reloaded (the sandbox / online editor of the playground:
 * code changed while it runs). Without it, the scene starts again, with the parameters it was entered with.
 *
 *   public function hotState(): array { return ['level' => $this->level, 'score' => $this->score]; }
 *   public function hotRestore(array $state): void { $this->level = $state['level']; $this->score = $state['score']; }
 *
 * hotState() is plain data (it goes through JSON: numbers, strings, arrays), taken just before the reload;
 * hotRestore() is called just after enter(), with the new code: check what you read (a field may have changed).
 */
interface HotReloadable
{
    /** @return array<string, mixed> */
    public function hotState(): array;

    /** @param array<string, mixed> $state */
    public function hotRestore(array $state): void;
}
