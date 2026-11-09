<?php

namespace JumPHP\Tests\Input;

use JumPHP\Bridge\JsBridge;
use JumPHP\Input\ActionMap;
use JumPHP\Input\Gamepad;
use JumPHP\Input\Keyboard;
use JumPHP\Input\Mouse;
use JumPHP\Input\Touch;
use PHPUnit\Framework\TestCase;

final class GamepadTest extends TestCase
{
    private function state(array $buttons, array $axes = [0, 0, 0, 0], bool $standard = true): array
    {
        $b = array_fill(0, 17, 0.0);
        foreach ($buttons as $i => $v) {
            $b[$i] = $v;
        }
        return ['pads' => [['i' => 0, 'id' => 'Test pad', 'std' => $standard, 'b' => $b, 'a' => $axes]]];
    }

    public function testStandardNamesPressedHeldAndAxes(): void
    {
        $bridge = new JsBridge();
        $pad = new Gamepad($bridge);
        $events = [];
        $pad->on('connect', function (int $i, string $name) use (&$events) { $events[] = "connect $i $name"; });
        $pad->on('disconnect', function (int $i) use (&$events) { $events[] = "disconnect $i"; });

        $bridge->emit('gamepad', [$this->state([0 => 1.0, 9 => 1.0, 7 => 0.8], [-1.0, 0.1, 0.0, 0.6])]);
        $pad->update();
        self::assertSame(['connect 0 Test pad'], $events);
        self::assertTrue($pad->isPressed('pad0:a'));
        self::assertTrue($pad->isHeld('pad0:start'));
        self::assertTrue($pad->isHeld('pad0:button9'));
        self::assertTrue($pad->isHeld('pad0:rt'));
        self::assertTrue($pad->isHeld('pad0:left'), 'left stick = direction');
        self::assertTrue($pad->isHeld('pad0:ls_left'));
        self::assertFalse($pad->isHeld('pad0:up'), 'dead zone');
        self::assertSame(-1.0, $pad->axis(0, 'lx'));
        self::assertSame(0.0, $pad->axis(0, 'ly'));
        self::assertEqualsWithDelta(0.8, $pad->axis(0, 'rt'), 1e-9);
        self::assertTrue($pad->isHeld('pad0:rs_down'));

        $pad->resetTransientStates();
        $pad->update();
        self::assertFalse($pad->isPressed('pad0:a'), 'pressed once');
        self::assertTrue($pad->isHeld('pad0:a'));

        $bridge->emit('gamepad', [['pads' => []]]);
        $pad->update();
        self::assertSame('disconnect 0', $events[1]);
        self::assertFalse($pad->isHeld('pad0:a'));
        self::assertSame(0, $pad->count());
    }

    public function testDpadAndActions(): void
    {
        $bridge = new JsBridge();
        $pad = new Gamepad($bridge);
        $actions = new ActionMap(new Keyboard(), $pad, new Touch(), new Mouse());
        $actions->load(['jump' => ['pad0:a'], 'left' => ['pad0:left']]);
        $bridge->emit('gamepad', [$this->state([0 => 1.0, 14 => 1.0])]);
        $pad->update();
        self::assertTrue($actions->isPressed('jump'));
        self::assertTrue($actions->isHeld('left'));
        self::assertSame(-1, (int)$actions->axis('left', 'right'));
    }

    public function testNonStandardPadUsesXboxNumbers(): void
    {
        $bridge = new JsBridge();
        $pad = new Gamepad($bridge);
        // Xbox numbering: 7 = start, triggers are axes 2 / 5 (-1 at rest)
        $bridge->emit('gamepad', [$this->state([7 => 1.0], [0, 0, -1.0, 0, 0, 1.0], false)]);
        $pad->update();
        self::assertTrue($pad->isHeld('pad0:start'));
        self::assertFalse($pad->isHeld('pad0:lt'));
        self::assertTrue($pad->isHeld('pad0:rt'));
        self::assertSame(0.0, $pad->axis(0, 'lt'));
    }
}
