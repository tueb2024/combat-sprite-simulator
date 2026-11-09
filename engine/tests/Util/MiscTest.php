<?php

namespace JumPHP\Tests\Util;

use JumPHP\Audio\Synth;
use JumPHP\Loop\FixedStep;
use JumPHP\Tile\CollisionMask;
use JumPHP\Util\Color;
use JumPHP\Util\Lang;
use PHPUnit\Framework\TestCase;

final class MiscTest extends TestCase
{
    public function testColor(): void
    {
        self::assertSame('255,136,0,255', Color::of('#f80')->key());
        self::assertSame('1,2,3,4', Color::of([1, 2, 3, 4])->key());
        self::assertSame('16,32,48,128', Color::of('#10203080')->key());
    }

    public function testFixedStep(): void
    {
        $step = new FixedStep(60);
        $n = 0;
        $step->run(0.05, function () use (&$n): void { $n++; });
        self::assertSame(3, $n);
        self::assertEqualsWithDelta(0.05 * 60 - 3, $step->alpha(), 1e-9);
    }

    public function testSynthWav(): void
    {
        $wav = Synth::tone(440, 0.1);
        self::assertStringStartsWith('RIFF', $wav);
        self::assertSame(44 + (int)(0.1 * Synth::RATE) * 2, strlen($wav));
    }

    public function testMasks(): void
    {
        $slope = CollisionMask::slope(16, 16, 0, 16);
        self::assertFalse($slope->isSolid(0, 0));
        self::assertTrue($slope->isSolid(15, 0));
        self::assertTrue($slope->isSolid(0, 15) === false);
        self::assertSame(8, CollisionMask::parse('heights:8,8', 16, 16)->topAt(1, 16));
        self::assertTrue(CollisionMask::parse('oneway-slope:0,16', 16, 16)->oneWay);
        self::assertNull(CollisionMask::parse('none', 16, 16)->topAt(0, 16));
    }

    public function testLang(): void
    {
        $lang = new Lang('fr', ['fr' => ['PLAY' => 'JOUER', 'Hi {name}' => 'Salut {name}']]);
        self::assertSame('JOUER', Lang::t('PLAY'));
        self::assertSame('Salut Max', Lang::t('Hi {name}', ['name' => 'Max']));
        self::assertSame('QUIT', Lang::t('QUIT'));
        $lang->setLang('en');
        self::assertSame('PLAY', Lang::t('PLAY'));
    }
}
