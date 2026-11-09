<?php

namespace JumPHP\Tests\Input;

use JumPHP\Input\Keyboard;
use PHPUnit\Framework\TestCase;

final class KeyboardModifiersTest extends TestCase
{
    public function testCtrlIsRememberedForAKeyTappedAndLetGoWithinTheFrame(): void
    {
        $k = new Keyboard();
        $k->down(1073742048);   // left Ctrl
        $k->down(8);            // Backspace
        $k->up(8);
        $k->up(1073742048);     // all over before the frame reads the modifier state
        self::assertFalse($k->ctrlHeld());
        self::assertTrue($k->typedWithCtrl());
        $k->resetTransientStates();
        self::assertFalse($k->typedWithCtrl());
    }

    public function testAKeyAloneIsNotCtrl(): void
    {
        $k = new Keyboard();
        $k->down(8);
        self::assertFalse($k->typedWithCtrl());
        self::assertFalse($k->typedWithShift());
    }
}
