<?php

namespace JumPHP\Tests\Ui;

use JumPHP\Input\ActionMap;
use JumPHP\Input\Gamepad;
use JumPHP\Input\Keyboard;
use JumPHP\Input\Mouse;
use JumPHP\Input\Touch;
use JumPHP\Ui\Menu;
use PHPUnit\Framework\TestCase;

final class MenuTest extends TestCase
{
    private function actions(): ActionMap
    {
        return new ActionMap(new Keyboard(), new Gamepad(), new Touch(), new Mouse());
    }

    public function testNavigationSkipsSeparatorsAndPresses(): void
    {
        $pressed = null;
        $volume = 5;
        $menu = new Menu([
            ['PLAY', null, function () use (&$pressed): void { $pressed = 'play'; }],
            ['-----'],
            ['VOLUME', function () use (&$volume): string { return (string)$volume; }, null, function (int $d) use (&$volume): void { $volume += $d; }],
        ]);
        $actions = $this->actions();

        $actions->press('down');
        $menu->update($actions);
        self::assertSame(2, $menu->selected, 'the separator is skipped');
        $actions->endFrame();

        $actions->press('right');
        $menu->update($actions);
        self::assertSame(6, $volume);
        self::assertSame('VOLUME  6', $menu->text($menu->getItems()[2]));
        $actions->endFrame();

        $actions->press('down');      // wraps to the first item
        $menu->update($actions);
        $actions->endFrame();
        $actions->press('confirm');
        $menu->update($actions);
        self::assertSame('play', $pressed);
    }

    public function testKeyboardBindings(): void
    {
        $keyboard = new Keyboard();
        $actions = new ActionMap($keyboard, new Gamepad(), new Touch(), new Mouse());
        $actions->bind('jump', ['Space', 'W']);
        $keyboard->down(ord('w'));
        self::assertTrue($actions->isPressed('jump'));
        self::assertTrue($actions->isHeld('jump'));
        $keyboard->resetTransientStates();
        self::assertFalse($actions->isPressed('jump'));
        self::assertTrue($actions->isHeld('jump'));
        self::assertSame(1, $actions->axis('left', 'jump'));
    }
}
