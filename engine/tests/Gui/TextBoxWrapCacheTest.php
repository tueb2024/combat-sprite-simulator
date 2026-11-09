<?php

namespace JumPHP\Tests\Gui;

use JumPHP\Gui\TextBox;
use JumPHP\Gui\Theme;
use JumPHP\Renderer\Renderer;
use JumPHP\Renderer\TextDrawer;
use PHPUnit\Framework\TestCase;

/** A multi-line box is drawn every frame: its wrapping (a font measure per character) is done again only when something changed. */
final class TextBoxWrapCacheTest extends TestCase
{
    public function testTheWrappingIsMeasuredOnceNotEveryFrame(): void
    {
        $measures = 0;
        $font = new class($measures) implements TextDrawer {
            public function __construct(private int &$n)
            {
            }

            public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, $color = [255, 255, 255], string $align = 'left', $shadow = null): void
            {
            }

            public function width(string $text, float $scale = 1): int
            {
                $this->n++;
                return mb_strlen($text) * 8;
            }

            public function height(float $scale = 1): int
            {
                return 12;
            }

            public function lineHeight(float $scale = 1): int
            {
                return 16;
            }
        };
        $theme = new Theme();
        $fonts = new \ReflectionProperty($theme, 'fonts');
        $fonts->setValue($theme, [(string)max(6, (int)round($theme->fontSize * $theme->scale)) => $font]);

        $box = new TextBox(['multiline' => true]);
        $box->props['text'] = "hello wrapped world\nsecond paragraph";
        $box->cursor = 5;
        $lines = new \ReflectionMethod($box, 'lines');

        [$wrapped, $cursorLine, $cursorCol] = $lines->invoke($box, $theme, 80);
        $first = $measures;
        self::assertGreaterThan(0, $first);
        self::assertSame(['hello wrap', 'ped world', 'second par', 'agraph'], $wrapped);

        for ($i = 0; $i < 10; $i++) {
            $lines->invoke($box, $theme, 80);
        }
        self::assertSame($first, $measures, 'the same text and width: nothing is measured again');

        $box->props['text'] .= '!';
        $lines->invoke($box, $theme, 80);
        self::assertGreaterThan($first, $measures, 'a changed text is measured');
        $after = $measures;
        $lines->invoke($box, $theme, 160);
        self::assertGreaterThan($after, $measures, 'a changed width is measured');
    }
}
