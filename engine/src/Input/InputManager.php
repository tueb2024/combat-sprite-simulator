<?php

namespace JumPHP\Input;

use Evenement\EventEmitter;
use JumPHP\Renderer\Renderer;

/**
 * Drains the SDL event queue once per frame and feeds keyboard (keys, typed text), mouse (buttons, wheel), touch and gamepads.
 * Events: 'quit' (window closed), 'key' (int $sym, on key down), 'resize' (int $w, int $h),
 * 'window' (int SDL_WINDOWEVENT_*, int $data1, int $data2).
 */
class InputManager extends EventEmitter
{
    /** SDLK_AC_BACK: Android's back button (no constant in the binding) */
    private const SDLK_AC_BACK = 0x4000010E;

    public function __construct(
        private Keyboard $keyboard,
        private Mouse $mouse,
        private Touch $touch,
        private Gamepad $gamepad,
        private ActionMap $actions,
    ) {
    }

    private ?TouchControls $controls = null;

    /** The engine's own on-screen controls (native Android app): they see the touches first. */
    public function setControls(?TouchControls $controls): void
    {
        $this->controls = $controls;
    }

    public function getControls(): ?TouchControls
    {
        return $this->controls;
    }

    /** A key going down as if typed (on-screen buttons): held until Keyboard::up(). */
    public function pressKey(int $key): void
    {
        $this->keyboard->down($key);
        $this->emit('key', [$key]);
    }

    public function poll(?Renderer $renderer = null): void
    {
        $this->controls?->beforeEvents();
        $event = new \SDL_Event();
        while (\SDL_PollEvent($event)) {
            switch ($event->type) {
                case \SDL_QUIT:
                    $this->emit('quit');
                    break;
                case \SDL_KEYDOWN:
                case \SDL_KEYUP:
                    $sym = $event->key->keysym->sym;
                    if ($sym === self::SDLK_AC_BACK) {
                        // Android's back button (or gesture): Escape, the key of back / pause in every game
                        $sym = \SDLK_ESCAPE;
                    }
                    if ($event->type === \SDL_KEYUP) {
                        $this->keyboard->up($sym);
                    } elseif ($sym === $event->key->keysym->sym) {
                        $this->keyboard->handle($event);
                        $this->emit('key', [$sym]);
                    } elseif (!$this->keyboard->isKeyHeld($sym)) {
                        $this->pressKey($sym);
                    }
                    break;
                case \SDL_MOUSEMOTION:
                    if ($event->motion !== null) {
                        $this->moveMouse($renderer, $event->motion->x, $event->motion->y);
                    } else {
                        $this->mouseFromState($renderer, null);
                    }
                    break;
                case \SDL_MOUSEBUTTONDOWN:
                case \SDL_MOUSEBUTTONUP:
                    $down = $event->type === \SDL_MOUSEBUTTONDOWN;
                    if ($event->button !== null) {
                        if ($down) {
                            // not on release: the release of a touch (synthesised by SDL) says 0,0
                            $this->moveMouse($renderer, $event->button->x, $event->button->y);
                        }
                        $down ? $this->mouse->down($event->button->button) : $this->mouse->up($event->button->button);
                    } else {
                        $this->mouseFromState($renderer, $down);
                    }
                    break;
                case \SDL_MOUSEWHEEL:
                    $wheel = $event->wheel ?? null;
                    if ($wheel !== null) {
                        $this->mouse->wheel((float)($wheel->preciseX ?? $wheel->x), (float)($wheel->preciseY ?? $wheel->y));
                    }
                    break;
                case \SDL_TEXTINPUT:
                    if (($event->text ?? null) !== null) {
                        $this->keyboard->addText((string)$event->text->text);
                    }
                    break;
                case \SDL_TEXTEDITING:
                    if (($event->edit ?? null) !== null) {
                        $this->keyboard->setComposition((string)$event->edit->text);
                    }
                    break;
                case \SDL_FINGERDOWN:
                case \SDL_FINGERUP:
                case \SDL_FINGERMOTION:
                    if ($event->tfinger !== null && !($renderer !== null && $this->controls?->handle($event, $renderer))) {
                        $this->touch->handle($event);
                    }
                    break;
                case \SDL_JOYBUTTONDOWN:
                case \SDL_JOYBUTTONUP:
                case \SDL_JOYAXISMOTION:
                    $this->gamepad->handle($event);
                    break;
                case 0x605: // SDL_JOYDEVICEADDED (no constant in the binding)
                    $this->gamepad->open();
                    break;
                case 0x606: // SDL_JOYDEVICEREMOVED: no data in the binding's event, close them all and reopen
                    for ($i = $this->gamepad->count() - 1; $i >= 0; $i--) {
                        $this->gamepad->close($i);
                    }
                    $this->gamepad->open();
                    break;
                case \SDL_WINDOWEVENT:
                    if ($event->window !== null) {
                        $w = $event->window;
                        $this->emit('window', [(int)$w->event, (int)$w->data1, (int)$w->data2]);
                        if ($w->event === \SDL_WINDOWEVENT_SIZE_CHANGED) {
                            $this->emit('resize', [$w->data1, $w->data2]);
                        }
                    }
                    break;
            }
        }
        $this->gamepad->update();
    }

    /**
     * Some builds of the binding (the browser one) give no button / motion data with the mouse events:
     * read the position and the buttons from SDL_GetMouseState() instead.
     * @param bool|null $down true: a button went down, false: up, null: a move
     */
    private function mouseFromState(?Renderer $renderer, ?bool $down): void
    {
        $x = 0;
        $y = 0;
        $mask = (int)\SDL_GetMouseState($x, $y);
        if ($down !== false) {
            // not on release: after a touch SDL may report 0,0
            $this->moveMouse($renderer, (float)$x, (float)$y);
        }
        if ($down === null) {
            return;
        }
        $changed = false;
        for ($button = 1; $button <= 5; $button++) {
            $isDown = ($mask & (1 << ($button - 1))) !== 0;
            if ($down && $isDown && !$this->mouse->isHeld($button)) {
                $this->mouse->down($button);
                $changed = true;
            } elseif (!$down && !$isDown && $this->mouse->isHeld($button)) {
                $this->mouse->up($button);
                $changed = true;
            }
        }
        if (!$changed) { // no button information at all: the left button
            $down ? $this->mouse->down(1) : $this->mouse->up(1);
        }
    }

    private function moveMouse(?Renderer $renderer, float $x, float $y): void
    {
        if ($renderer !== null) {
            // window coordinates are logical pixels; the renderer output may be HiDPI
            [$x, $y] = $renderer->windowToView($x, $y);
        }
        $this->mouse->move($x, $y);
    }

    /** End of frame: "pressed this frame" states are forgotten. */
    public function endFrame(): void
    {
        $this->keyboard->resetTransientStates();
        $this->mouse->resetTransientStates();
        $this->touch->resetTransientStates();
        $this->gamepad->resetTransientStates();
        $this->actions->endFrame();
    }

    public function getKeyboard(): Keyboard { return $this->keyboard; }
    public function getMouse(): Mouse { return $this->mouse; }
    public function getTouch(): Touch { return $this->touch; }
    public function getGamepad(): Gamepad { return $this->gamepad; }
    public function getActions(): ActionMap { return $this->actions; }
}
