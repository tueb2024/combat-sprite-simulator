<?php

namespace JumPHP\Input;

/**
 * Keyboard state by SDL key code (\SDLK_UP, \SDLK_SPACE, \SDLK_a ...):
 * held = down now, pressed = went down this frame, released = went up this frame.
 */
class Keyboard
{
    /** @var array<int, bool> */
    private array $held = [];
    /** @var array<int, bool> */
    private array $pressed = [];
    /** @var array<int, bool> */
    private array $released = [];
    /** @var int[] held keys, the last pressed at the end */
    private array $order = [];
    /** @var int[] keys that went down this frame, in order (text typing) */
    private array $typed = [];
    /** Ctrl / Shift were held when a key went down this frame (a quick tap is over before the frame reads the modifier state) */
    private bool $typedCtrl = false;
    private bool $typedShift = false;
    /** the text typed this frame (SDL_TEXTINPUT: the layout, dead keys and input methods applied) */
    private string $text = '';
    /** what an input method is composing (not typed yet) */
    private string $composition = '';
    /** the binding sends text events (older engines do not: then text comes from the key codes) */
    private bool $textEvents = false;

    public function handle($event): void
    {
        $key = $event->key->keysym->sym;
        if ($event->type === \SDL_KEYDOWN) {
            $this->down($key);
        } elseif ($event->type === \SDL_KEYUP) {
            $this->up($key);
        }
    }

    public function down(int $key): void
    {
        if (!isset($this->held[$key])) {
            // auto-repeat sends KEYDOWN again while held: only the first one is a press
            $this->pressed[$key] = true;
            $this->order[] = $key;
            $this->typed[] = $key;
            $this->typedCtrl = $this->typedCtrl || $this->ctrlHeld();
            $this->typedShift = $this->typedShift || $this->shiftHeld();
        }
        $this->held[$key] = true;
    }

    /** Ctrl (or the Cmd / GUI key) is down now. */
    public function ctrlHeld(): bool
    {
        return isset($this->held[1073742048]) || isset($this->held[1073742052]) || isset($this->held[1073742051]) || isset($this->held[1073742055]);
    }

    public function shiftHeld(): bool
    {
        return isset($this->held[1073742049]) || isset($this->held[1073742053]);
    }

    /** Ctrl was down when a key went down this frame, even if it is let go already. */
    public function typedWithCtrl(): bool
    {
        return $this->typedCtrl;
    }

    public function typedWithShift(): bool
    {
        return $this->typedShift;
    }

    public function up(int $key): void
    {
        $this->released[$key] = true;
        unset($this->held[$key]);
        $this->order = array_values(array_filter($this->order, fn (int $k) => $k !== $key));
    }

    public function isKeyHeld(int $key): bool
    {
        return isset($this->held[$key]);
    }

    public function isKeyPressed(int $key): bool
    {
        return isset($this->pressed[$key]);
    }

    public function isKeyReleased(int $key): bool
    {
        return isset($this->released[$key]);
    }

    /** The most recently pressed key still held (left + right: the last one wins). */
    public function getLastKeyPressed(): ?int
    {
        return $this->order === [] ? null : end($this->order);
    }

    /** @return int[] keys that went down this frame */
    public function getTyped(): array
    {
        return $this->typed;
    }

    /** Text typed (UTF-8), from the system's text input. */
    public function addText(string $text): void
    {
        $this->textEvents = true;
        $this->text .= $text;
        $this->composition = '';
    }

    /** @return string the text typed this frame */
    public function getText(): string
    {
        return $this->text;
    }

    public function setComposition(string $text): void
    {
        $this->textEvents = true;
        $this->composition = $text;
    }

    /** What an input method (or a dead key) is composing, shown at the cursor until it is typed. */
    public function getComposition(): string
    {
        return $this->composition;
    }

    /** True once text events came: the typed text is then getText(), not the key codes. */
    public function hasTextEvents(): bool
    {
        return $this->textEvents;
    }

    public function anyPressed(): bool
    {
        return $this->pressed !== [];
    }

    /** End of frame: pressed / released only last one frame. */
    public function resetTransientStates(): void
    {
        $this->pressed = [];
        $this->released = [];
        $this->typed = [];
        $this->typedCtrl = $this->typedShift = false;
        $this->text = '';
    }

    public function releaseAll(): void
    {
        foreach (array_keys($this->held) as $key) {
            $this->up($key);
        }
    }
}
