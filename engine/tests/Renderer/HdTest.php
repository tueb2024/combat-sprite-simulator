<?php

namespace JumPHP\Tests\Renderer;

use JumPHP\Kernel\GameConfig;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\Sdl;
use JumPHP\Renderer\TtfFont;
use PHPUnit\Framework\TestCase;

/**
 * HD 2D (game.yaml `hd`, Renderer::setHd()) and TtfFont's options: off by default, nothing changes for a game that
 * does not ask for them; on, the view texture is bigger while every size stays in view pixels.
 */
final class HdTest extends TestCase
{
    private const FONT = __DIR__ . '/../../../skeleton-gui/assets/fonts/ui.ttf';

    private static ?Sdl $sdl = null;

    /** One headless SDL (software renderer into a surface) for the class, back to no HD before each test. */
    private function renderer(): Renderer
    {
        if (self::$sdl === null) {
            putenv('JUMPHP_TEST=1');
            self::$sdl = new Sdl(new GameConfig([], sys_get_temp_dir()));
            self::$sdl->initSDL(false, 'hd', 320, 180, false, 1);
        }
        $r = self::$sdl->getRenderer();
        $r->setHd(1);
        return $r;
    }

    public function testHdIsOffByDefault(): void
    {
        $r = $this->renderer();
        self::assertSame(1, $r->hd());
        self::assertSame([320, 180], $r->viewPixels());
        self::assertSame([$r->getWidth(), $r->getHeight()], $r->viewPixels());
    }

    public function testAFixedHdMakesTheViewTextureBiggerAndKeepsTheViewSize(): void
    {
        $r = $this->renderer();
        $r->setHd(3);
        self::assertSame(3, $r->hd());
        self::assertSame([960, 540], $r->viewPixels());
        self::assertSame(320, $r->getWidth());
        self::assertSame(180, $r->getHeight());

        // a whole frame draws without an error, translation included
        $r->beginFrame([10, 20, 30]);
        $r->translate(5, 5);
        $r->fillRect(0.5, 0.5, 10.25, 3, [255, 0, 0, 128]);
        $r->fillCircle(40, 40, 6.5, '#00ff00');
        $r->drawLine(0, 0, 50.5, 20.5, [255, 255, 255]);
        $r->translate(-5, -5);
        $r->clear([0, 0, 0, 0], true);
        $r->endFrame();

        $r->setHd(1);
        self::assertSame([320, 180], $r->viewPixels());
    }

    public function testTextKeepsItsSizeInViewPixelsWithAndWithoutHd(): void
    {
        if (!is_file(self::FONT)) {
            self::markTestSkipped('no font file');
        }
        $this->renderer();
        $plain = new TtfFont(self::$sdl, self::FONT, 8);
        $width = $plain->width('Hello world', 2);
        $height = $plain->height(2);

        // the new options, unused: the same font
        $default = new TtfFont(self::$sdl, self::FONT, 8, false, 0, 0.0, 0.0);
        self::assertSame($width, $default->width('Hello world', 2));
        self::assertSame($height, $default->height(2));

        // in HD the glyphs are rendered at 4x their size, measured back in view pixels: about the same
        self::$sdl->getRenderer()->setHd(4);
        $hd = new TtfFont(self::$sdl, self::FONT, 8, true, TtfFont::BOLD, 0.3, 0.5);
        self::assertEqualsWithDelta($width, $hd->width('Hello world', 2), $width * 0.15);
        self::assertEqualsWithDelta($height, $hd->height(2), 3);
        $r = self::$sdl->getRenderer();
        $r->beginFrame();
        $hd->draw($r, "Hello\nworld", 10, 10, 2, [255, 255, 255], 'center', [0, 0, 0]);
        $r->endFrame();
        $r->setHd(1);
    }
}
