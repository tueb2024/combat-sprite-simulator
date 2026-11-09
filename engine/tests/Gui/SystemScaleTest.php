<?php

namespace JumPHP\Tests\Gui;

use JumPHP\Gui\Form;
use PHPUnit\Framework\TestCase;

final class SystemScaleTest extends TestCase
{
    public function testTheDesktopsDpiSettingGivesTheInterfaceScale(): void
    {
        self::assertSame(1.5, Form::scaleFromXrdb("Xft.antialias:\t1\nXft.dpi:\t144\nXft.hinting:\t1\n"));
        self::assertSame(1.0, Form::scaleFromXrdb("Xft.dpi:\t96\n"));
        self::assertSame(2.0, Form::scaleFromXrdb("Xft.dpi: 192"));
        self::assertSame(0.0, Form::scaleFromXrdb("*.background: black\n"));
        self::assertSame(0.0, Form::scaleFromXrdb(''));
    }
}
