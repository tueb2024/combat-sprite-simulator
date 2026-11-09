<?php

namespace JumPHP\Renderer;

/**
 * Timed captions over a scene or a whole game (a question, a reaction, a "wait for it", a call to action): each has a text, the seconds it
 * shows (from, to), where (top, middle, bottom, or a y in pixels), a size and colour, and a style:
 *
 *   plain  the text with a shadow            box    on a dark band (it fades in and out)
 *   pop    grows in with a small overshoot   type   written one letter after the other
 *
 * Long texts are cut into lines that fit the width. Made from a list (project.yaml `video: {captions: [...]}`, the scene's
 * `captions` param) or from `bin/jumphp video:make --caption "TEXT@2-5@middle@box"` (parseSpec). A scene draws them last:
 * `$captions->render($r, $this->font(), $this->time, $this->width(), $this->height())`. The engine cannot fade text (no alpha
 * on textures), so only a box fades; the others cut.
 *
 * Also over ANY game: JUMPHP_CAPTIONS=<json list> makes the engine draw them after the scene, on the game's own clock (`bin/jumphp video:replay
 * --caption ...`). JumPHP\Shorts\Captions (the jumphp/shorts extension) is this class under its old name.
 */
final class Captions
{
    public const AT = ['top', 'middle', 'bottom'];
    public const STYLES = ['plain', 'box', 'pop', 'type'];
    /** seconds a pop takes to reach its size, a box to fade, a letter to be typed */
    private const POP = 0.18;
    private const FADE = 0.2;
    private const LETTER = 0.04;

    /** @var list<array{text: string, from: float, to: float, at: string|int, scale: int, color: mixed, style: string}> */
    private array $items = [];

    /** @param list<array<string, mixed>> $list items of add() as arrays: text, from, to, at, scale, color, style */
    public static function fromList(array $list): self
    {
        $c = new self();
        foreach ($list as $item) {
            if (is_array($item) && isset($item['text'])) {
                $c->add((string)$item['text'], (float)($item['from'] ?? 0), (float)($item['to'] ?? 1.0E9), $item['at'] ?? 'bottom',
                    (int)($item['scale'] ?? 2), $item['color'] ?? '#ffffff', (string)($item['style'] ?? 'plain'));
            }
        }
        return $c;
    }

    /** "TEXT@FROM-TO[@AT[@STYLE]]": "Wait for it@2-5@middle@pop", "Follow!@10-@bottom" (no end: to the end of the video). */
    public static function parseSpec(string $spec): array
    {
        $parts = explode('@', $spec);
        $text = trim((string)array_shift($parts));
        $time = trim((string)array_shift($parts));
        if ($text === '' || !preg_match('/^(\d+(?:\.\d+)?)?\s*-\s*(\d+(?:\.\d+)?)?$/', $time, $m)) {
            throw new \InvalidArgumentException("caption '$spec': TEXT@FROM-TO[@top|middle|bottom|<y>][@plain|box|pop|type]");
        }
        $item = ['text' => $text, 'from' => (float)($m[1] ?? 0), 'to' => ($m[2] ?? '') !== '' ? (float)$m[2] : 1.0E9];
        foreach ($parts as $part) {
            $part = strtolower(trim($part));
            if (in_array($part, self::STYLES, true)) {
                $item['style'] = $part;
            } elseif (in_array($part, self::AT, true) || ctype_digit($part)) {
                $item['at'] = ctype_digit($part) ? (int)$part : $part;
            } elseif ($part !== '') {
                throw new \InvalidArgumentException("caption '$spec': '$part' is neither a place (top, middle, bottom, a number) nor a style (plain, box, pop, type)");
            }
        }
        return $item;
    }

    public function add(string $text, float $from, float $to = 1.0E9, string|int $at = 'bottom', int $scale = 2, mixed $color = '#ffffff', string $style = 'plain'): self
    {
        if (!in_array($style, self::STYLES, true)) {
            throw new \InvalidArgumentException("Unknown caption style '$style'");
        }
        $at = is_int($at) || ctype_digit((string)$at) ? (int)$at : (in_array($at, self::AT, true) ? $at : 'bottom');
        $this->items[] = ['text' => $text, 'from' => $from, 'to' => max($from, $to), 'at' => $at, 'scale' => max(1, min(6, $scale)), 'color' => $color, 'style' => $style];
        return $this;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * What shows at $time: the visible text (a typed one is cut), the size in 1/100 (a pop grows), the box's opacity (0-255).
     *
     * @return list<array{text: string, at: string|int, scale: float, color: mixed, style: string, alpha: int}>
     */
    public function active(float $time): array
    {
        $out = [];
        foreach ($this->items as $i) {
            if ($time < $i['from'] || $time >= $i['to']) {
                continue;
            }
            $age = $time - $i['from'];
            $text = $i['text'];
            $scale = (float)$i['scale'];
            if ($i['style'] === 'type') {
                $text = mb_substr($text, 0, (int)floor($age / self::LETTER) + 1);
            } elseif ($i['style'] === 'pop' && $age < self::POP) {
                $p = $age / self::POP;                       // 0 -> 1, overshoot to 1.15 on the way
                $scale *= $p < 0.7 ? $p / 0.7 * 1.15 : 1.15 - 0.15 * (($p - 0.7) / 0.3);
            }
            $fade = min(1.0, $age / self::FADE, ($i['to'] - $time) / self::FADE);
            $out[] = ['text' => $text, 'at' => $i['at'], 'scale' => max(0.5, $scale), 'color' => $i['color'], 'style' => $i['style'], 'alpha' => (int)round(170 * max(0.0, $fade))];
        }
        return $out;
    }

    /**
     * The lines of a text that fit $max pixels, measured by $width(text): words are kept whole (a longer one is cut).
     *
     * @param callable(string): int $width
     * @return list<string>
     */
    public static function wrap(string $text, int $max, callable $width): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $text) ?: [] as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) ?: [''] as $word) {
                while ($width($word) > $max && mb_strlen($word) > 1) {
                    // a word longer than a line: the longest start that fits, the rest goes on
                    $cut = mb_strlen($word) - 1;
                    while ($cut > 1 && $width(mb_substr($word, 0, $cut)) > $max) {
                        $cut--;
                    }
                    if ($line !== '') {
                        $lines[] = $line;
                        $line = '';
                    }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $try = $line === '' ? $word : "$line $word";
                if ($width($try) > $max && $line !== '') {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** Draws what shows at $time, centred, over everything drawn before. */
    public function render(Renderer $r, TextDrawer $font, float $time, int $width, int $height): void
    {
        // sizes and margins are written for a view 480 pixels high (the vertical videos): other views get them in proportion
        $k = $height / 480.0;
        $margin = max(4, (int)round(70 * $k));
        $pad = max(2, (int)round(6 * $k));
        foreach ($this->active($time) as $c) {
            $scale = max(1, (int)round($c['scale'] * $k));
            $lines = self::wrap($c['text'], $width - 24, fn (string $t): int => $font->width($t, $scale));
            $lineHeight = $font->lineHeight($scale);
            $block = count($lines) * $lineHeight;
            $y = match (true) {
                is_int($c['at']) => $c['at'],
                $c['at'] === 'top' => $margin,
                $c['at'] === 'middle' => intdiv($height - $block, 2),
                default => $height - $block - $margin,
            };
            if ($c['style'] === 'box' && $c['alpha'] > 0) {
                $r->fillRect(0, $y - $pad, $width, $block + 2 * $pad, [0, 0, 0, $c['alpha']]);
            }
            foreach ($lines as $n => $line) {
                $font->draw($r, $line, $width / 2, $y + $n * $lineHeight, $scale, $c['color'], 'center', [0, 0, 0]);
            }
        }
    }
}
