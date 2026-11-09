<?php

namespace JumPHP\Tests\Device;

use JumPHP\Device\Device;
use JumPHP\Device\Lifecycle;
use JumPHP\Bridge\JsBridge;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase
{
    public function testPageEventsOnceEach(): void
    {
        $life = new Lifecycle();
        $seen = [];
        foreach (['hide', 'show', 'blur', 'focus', 'online', 'offline', 'close'] as $name) {
            $life->on($name, function () use (&$seen, $name) { $seen[] = $name; });
        }
        $life->on('orientation', function (string $o) use (&$seen) { $seen[] = "o:$o"; });
        foreach (['hide', 'hide', 'show', 'blur', 'focus', 'offline', 'online', 'close'] as $e) {
            $life->pageEvent(['e' => $e]);
        }
        $life->pageEvent(['e' => 'resize', 'w' => 400, 'h' => 800, 'o' => 'portrait']);
        self::assertSame(['hide', 'show', 'blur', 'focus', 'offline', 'online', 'close', 'o:portrait'], $seen);
        self::assertSame([400, 800], $life->size());
        self::assertSame('portrait', $life->orientation());
        self::assertTrue($life->isVisible());
    }

    public function testInitialStateHasNoEvents(): void
    {
        $life = new Lifecycle();
        $count = 0;
        $life->on('hide', function () use (&$count) { $count++; });
        $life->pageEvent(['e' => 'state', 'visible' => false, 'focus' => false, 'online' => false, 'w' => 10, 'h' => 20, 'o' => 'portrait']);
        self::assertSame(0, $count);
        self::assertFalse($life->isVisible());
        self::assertFalse($life->isOnline());
        self::assertFalse($life->hasFocus());
    }

    public function testDesktopWindowEvents(): void
    {
        $life = new Lifecycle();
        $seen = [];
        $life->on('hide', function () use (&$seen) { $seen[] = 'hide'; });
        $life->on('show', function () use (&$seen) { $seen[] = 'show'; });
        $life->on('blur', function () use (&$seen) { $seen[] = 'blur'; });
        $life->windowEvent(\SDL_WINDOWEVENT_MINIMIZED);
        $life->windowEvent(\SDL_WINDOWEVENT_RESTORED);
        $life->windowEvent(\SDL_WINDOWEVENT_FOCUS_LOST);
        self::assertSame(['hide', 'show', 'blur'], $seen);
    }

    public function testDeviceStateFromPage(): void
    {
        $bridge = new JsBridge();
        $device = new Device($bridge);
        self::assertNull($device->tilt());
        $bridge->emit('device', [['tilt' => [0.5, -1], 'motion' => [0, 9.8, 0]]]);
        self::assertSame([0.5, -1.0], $device->tilt());
        self::assertSame([0.0, 9.8, 0.0], $device->motion());
        self::assertFalse($device->vibrate(50), 'desktop: no vibration');
    }
}
