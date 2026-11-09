<?php

namespace JumPHP\Renderer;

use JumPHP\Util\Color;

/**
 * Built-in 5x7 pixel font: no font file needed. Upper case only (lower case is drawn upper case),
 * digits and common punctuation; French accents fall back to the plain letter.
 *
 *   $font->draw($r, 'SCORE 12', 8, 8);                       // scale 1, white
 *   $font->draw($r, 'PAUSE', 160, 80, 3, '#ff0', 'center');  // centred on x = 160
 *
 * One atlas texture per colour (the SDL binding cannot tint a texture), made on first use.
 */
class BitmapFont implements TextDrawer
{
    public const GLYPH_WIDTH = 5;
    public const GLYPH_HEIGHT = 7;
    public const ADVANCE = 6;
    public const LINE_HEIGHT = 9;

    /** 7 rows of 5 pixels per glyph, rows separated by spaces. */
    private const GLYPHS = [
        ' ' => '..... ..... ..... ..... ..... ..... .....',
        'A' => '.###. #...# #...# ##### #...# #...# #...#',
        'B' => '####. #...# #...# ####. #...# #...# ####.',
        'C' => '.###. #...# #.... #.... #.... #...# .###.',
        'D' => '####. #...# #...# #...# #...# #...# ####.',
        'E' => '##### #.... #.... ####. #.... #.... #####',
        'F' => '##### #.... #.... ####. #.... #.... #....',
        'G' => '.###. #...# #.... #.### #...# #...# .####',
        'H' => '#...# #...# #...# ##### #...# #...# #...#',
        'I' => '.###. ..#.. ..#.. ..#.. ..#.. ..#.. .###.',
        'J' => '..### ...#. ...#. ...#. ...#. #..#. .##..',
        'K' => '#...# #..#. #.#.. ##... #.#.. #..#. #...#',
        'L' => '#.... #.... #.... #.... #.... #.... #####',
        'M' => '#...# ##.## #.#.# #.#.# #...# #...# #...#',
        'N' => '#...# #...# ##..# #.#.# #..## #...# #...#',
        'O' => '.###. #...# #...# #...# #...# #...# .###.',
        'P' => '####. #...# #...# ####. #.... #.... #....',
        'Q' => '.###. #...# #...# #...# #.#.# #..#. .##.#',
        'R' => '####. #...# #...# ####. #.#.. #..#. #...#',
        'S' => '.#### #.... #.... .###. ....# ....# ####.',
        'T' => '##### ..#.. ..#.. ..#.. ..#.. ..#.. ..#..',
        'U' => '#...# #...# #...# #...# #...# #...# .###.',
        'V' => '#...# #...# #...# #...# #...# .#.#. ..#..',
        'W' => '#...# #...# #...# #.#.# #.#.# #.#.# .#.#.',
        'X' => '#...# #...# .#.#. ..#.. .#.#. #...# #...#',
        'Y' => '#...# #...# .#.#. ..#.. ..#.. ..#.. ..#..',
        'Z' => '##### ....# ...#. ..#.. .#... #.... #####',
        '0' => '.###. #...# #..## #.#.# ##..# #...# .###.',
        '1' => '..#.. .##.. ..#.. ..#.. ..#.. ..#.. .###.',
        '2' => '.###. #...# ....# ...#. ..#.. .#... #####',
        '3' => '##### ...#. ..#.. ...#. ....# #...# .###.',
        '4' => '...#. ..##. .#.#. #..#. ##### ...#. ...#.',
        '5' => '##### #.... ####. ....# ....# #...# .###.',
        '6' => '..##. .#... #.... ####. #...# #...# .###.',
        '7' => '##### ....# ...#. ..#.. .#... .#... .#...',
        '8' => '.###. #...# #...# .###. #...# #...# .###.',
        '9' => '.###. #...# #...# .#### ....# ...#. .##..',
        '.' => '..... ..... ..... ..... ..... .##.. .##..',
        ',' => '..... ..... ..... ..... .##.. ..#.. .#...',
        ':' => '..... .##.. .##.. ..... .##.. .##.. .....',
        ';' => '..... .##.. .##.. ..... .##.. ..#.. .#...',
        '!' => '..#.. ..#.. ..#.. ..#.. ..#.. ..... ..#..',
        '?' => '.###. #...# ....# ...#. ..#.. ..... ..#..',
        '-' => '..... ..... ..... ##### ..... ..... .....',
        '+' => '..... ..#.. ..#.. ##### ..#.. ..#.. .....',
        '/' => '..... ....# ...#. ..#.. .#... #.... .....',
        '\\' => '..... #.... .#... ..#.. ...#. ....# .....',
        '(' => '...#. ..#.. .#... .#... .#... ..#.. ...#.',
        ')' => '.#... ..#.. ...#. ...#. ...#. ..#.. .#...',
        '[' => '.###. .#... .#... .#... .#... .#... .###.',
        ']' => '.###. ...#. ...#. ...#. ...#. ...#. .###.',
        "'" => '..#.. ..#.. .#... ..... ..... ..... .....',
        '"' => '.#.#. .#.#. ..... ..... ..... ..... .....',
        '%' => '##... ##..# ...#. ..#.. .#... #..## ...##',
        '<' => '...#. ..#.. .#... #.... .#... ..#.. ...#.',
        '>' => '.#... ..#.. ...#. ....# ...#. ..#.. .#...',
        '=' => '..... ..... ##### ..... ##### ..... .....',
        '_' => '..... ..... ..... ..... ..... ..... #####',
        '*' => '..... ..#.. #.#.# .###. #.#.# ..#.. .....',
        '#' => '.#.#. .#.#. ##### .#.#. ##### .#.#. .#.#.',
        '@' => '.###. #...# #.### #.#.# #.### #.... .####',
        '&' => '.##.. #..#. #.#.. .#... #.#.# #..#. .##.#',
        '$' => '..#.. .#### #.#.. .###. ..#.# ####. ..#..',
        '|' => '..#.. ..#.. ..#.. ..#.. ..#.. ..#.. ..#..',
        '^' => '..#.. .#.#. #...# ..... ..... ..... .....',
        '~' => '..... ..... .#... #.#.# ...#. ..... .....',
    ];

    private const ACCENTS = [
        'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Œ' => 'OE',
    ];

    /** @var array<string, int> glyph => column in the atlas */
    private array $index = [];
    /** @var array<string, mixed> colour key => atlas texture */
    private array $atlases = [];

    public function __construct(private Sdl $sdl)
    {
        $i = 0;
        foreach (array_keys(self::GLYPHS) as $char) {
            $this->index[(string)$char] = $i++;
        }
    }

    public function draw(Renderer $r, string $text, float $x, float $y, float $scale = 1, Color|array|string $color = [255, 255, 255],
                         string $align = 'left', Color|array|string|null $shadow = null): void
    {
        $text = $this->normalize($text);
        if ($shadow !== null) {
            $this->draw($r, $text, $x + $scale, $y + $scale, $scale, $shadow, $align);
        }
        $atlas = $this->atlas(Color::of($color));
        $lines = explode("\n", $text);
        foreach ($lines as $n => $line) {
            $lineX = $x;
            if ($align !== 'left') {
                $w = $this->width($line, $scale);
                $lineX -= $align === 'center' ? $w / 2 : $w;
            }
            $lineY = $y + $n * self::LINE_HEIGHT * $scale;
            for ($i = 0, $len = strlen($line); $i < $len; $i++) {
                $col = $this->index[$line[$i]] ?? $this->index['?'];
                if ($line[$i] !== ' ') {
                    $r->copy($atlas, [$col * self::GLYPH_WIDTH, 0, self::GLYPH_WIDTH, self::GLYPH_HEIGHT],
                        $lineX + $i * self::ADVANCE * $scale, $lineY, self::GLYPH_WIDTH * $scale, self::GLYPH_HEIGHT * $scale);
                }
            }
        }
    }

    public function width(string $text, float $scale = 1): int
    {
        $lines = explode("\n", $this->normalize($text));
        $max = max(array_map('strlen', $lines));
        return $max === 0 ? 0 : (int)round(($max * self::ADVANCE - 1) * $scale);
    }

    public function height(float $scale = 1): int
    {
        return (int)round(self::GLYPH_HEIGHT * $scale);
    }

    public function lineHeight(float $scale = 1): int
    {
        return (int)round(self::LINE_HEIGHT * $scale);
    }

    private function normalize(string $text): string
    {
        return strtoupper(strtr(mb_strtoupper($text, 'UTF-8'), self::ACCENTS));
    }

    private function atlas(Color $color)
    {
        $key = $color->key();
        if (isset($this->atlases[$key])) {
            return $this->atlases[$key];
        }
        $rows = array_fill(0, self::GLYPH_HEIGHT, '');
        foreach (self::GLYPHS as $glyph) {
            foreach (explode(' ', $glyph) as $y => $row) {
                $rows[$y] .= $row;
            }
        }
        $slices = $this->sdl->createTextureFromPixels('jumphp-font-' . $key, $rows, ['#' => $color]);
        return $this->atlases[$key] = $slices[0]['texture'];
    }
}
