<?php

namespace JumPHP\Tests\Input;

use JumPHP\Input\ActionMap;
use JumPHP\Input\Gamepad;
use JumPHP\Input\InputManager;
use JumPHP\Input\Keyboard;
use JumPHP\Input\Mouse;
use JumPHP\Input\Touch;
use JumPHP\Input\TouchControls;
use JumPHP\Kernel\GameConfig;
use JumPHP\Renderer\Sdl;
use PHPUnit\Framework\TestCase;

/**
 * The engine's own on-screen controls (native apps) read game.yaml `touch` like engine/web/touch.js: the d-pad by
 * default, the joystick with `pad: stick`, nothing at all with `pad: none` (games played by tapping).
 */
final class TouchControlsTest extends TestCase
{
    private static ?Sdl $sdl = null;

    /** @return list<string> the controls laid out for a headless renderer */
    private function buttons(array $touch): array
    {
        if (self::$sdl === null) {
            putenv('JUMPHP_TEST=1');
            self::$sdl = new Sdl(new GameConfig([], sys_get_temp_dir()));
            self::$sdl->initSDL(false, 'touch', 320, 180, false, 1);
        }
        $keyboard = new Keyboard();
        $mouse = new Mouse();
        $gamepad = new Gamepad();
        $touchInput = new Touch();
        $input = new InputManager($keyboard, $mouse, $touchInput, $gamepad, new ActionMap($keyboard, $gamepad, $touchInput, $mouse));
        $controls = new TouchControls($keyboard, $mouse, $input, $touch);
        (new \ReflectionMethod($controls, 'layout'))->invoke($controls, self::$sdl->getRenderer());
        return array_keys((new \ReflectionProperty($controls, 'buttons'))->getValue($controls));
    }

    public function testTheDefaultIsTheDpadWithItsButtons(): void
    {
        $buttons = $this->buttons([]);
        foreach (['left', 'right', 'up', 'down', 'a', 'b', 'start'] as $name) {
            self::assertContains($name, $buttons);
        }
    }

    public function testTheStickHasNoArrows(): void
    {
        $buttons = $this->buttons(['pad' => 'stick']);
        self::assertNotContains('left', $buttons);
        self::assertContains('a', $buttons);
    }

    public function testPadNoneShowsNoControlAtAll(): void
    {
        self::assertSame([], $this->buttons(['pad' => 'none']));
    }
}
