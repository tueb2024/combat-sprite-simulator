<?php

namespace JumPHP\Input;

/**
 * A played session as scripted input: JUMPHP_INPUT_RECORD=<file> makes the game write, as it is played, the actions that went
 * down and up: [[frame, "action", "hold"|"release"], ...], the very format of JUMPHP_INPUT. Played back with
 * `bin/jumphp video:replay <game> --input <file>` (a film of the session), or `JUMPHP_INPUT=$(cat <file>) bin/jumphp shot <game>`.
 * An action pressed and let go inside one frame is recorded as a press: [frame, "action"]. Called from the engine's 'frame' event, before the
 * frame's input is cleared (Application).
 */
final class InputRecorder
{
    /** @var list<array{0: int, 1: string, 2: string}> */
    private array $events = [];

    /** @var array<string, bool> */
    private array $held = [];

    public function __construct(private string $file)
    {
    }

    /** In the frame $frame, after its update: what changed in the actions' held state is an event of that frame, a tap too. */
    public function observe(int $frame, ActionMap $actions): void
    {
        $changed = false;
        foreach ($actions->names() as $name) {
            $now = $actions->isHeld($name);
            if ($now !== ($this->held[$name] ?? false)) {
                $this->held[$name] = $now;
                $this->events[] = [$frame, $name, $now ? 'hold' : 'release'];
                $changed = true;
            } elseif (!$now && $actions->isPressed($name)) {
                $this->events[] = [$frame, $name];             // down and up inside one frame: a tap
                $changed = true;
            }
        }
        if ($changed) {
            $this->save();
        }
    }

    /** @return list<array{0: int, 1: string, 2: string}> */
    public function events(): array
    {
        return $this->events;
    }

    public function save(): void
    {
        @mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode($this->events));
    }
}
