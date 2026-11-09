<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * Native JumPHP address bar: works on /play/ and the playground without site HTML.
 * SDL_TEXTINPUT supports French keyboard layouts and UTF-8; the in-game keypad
 * remains usable with a controller, mouse or touchscreen.
 */
final class AddressEntryScene extends AbstractScene
{
    private string $address = '';
    private string $message = 'TYPE AN ADDRESS OR USE THE KEYPAD';
    private int $selected = 0;

    /** @var list<string> */
    private const KEYS = [
        'A','B','C','D','E','F','G','H','I','J',
        'K','L','M','N','O','P','Q','R','S','T',
        'U','V','W','X','Y','Z','0','1','2','3',
        '4','5','6','7','8','9','-', '.', ',', "'",
        'SPACE', 'DEL', 'GO',
    ];

    public function enter(array $params = []): void
    {
        $this->address = (string) ($params['address'] ?? '');
        $this->message = 'TYPE AN ADDRESS OR USE THE KEYPAD';
        $this->selected = 0;
    }

    public function update(float $dt): void
    {
        $keyboard = $this->engine->input->getKeyboard();
        if ($this->actions()->isPressed('back')) {
            $this->scenes()->switchTo(TitleScene::class, [], false);
            return;
        }

        // Physical keyboard Enter submits; gamepad confirm selects a keypad key.
        foreach ($keyboard->getTyped() as $key) {
            if ($key === 13 || $key === 1073741912) {
                $this->submit();
                return;
            }
            if ($key === 8) {
                $this->backspace();
                return;
            }
        }

        $text = $keyboard->getText();
        if ($text !== '') {
            $this->append($text);
            return;
        }
        if (!$keyboard->hasTextEvents()) {
            // Fallback for older SDL builds without TEXTINPUT.
            $typed = '';
            foreach ($keyboard->getTyped() as $key) {
                if (($key >= 32 && $key <= 126) && !in_array($key, [127], true)) {
                    $typed .= chr($key);
                }
            }
            if ($typed !== '') {
                $this->append($typed);
                return;
            }
        }

        $mouse = $this->engine->input->getMouse();
        if ($mouse->isPressed(1)) {
            $index = $this->keyAt($mouse->x, $mouse->y);
            if ($index !== null) {
                $this->selected = $index;
                $this->activateKey();
            }
            return;
        }

        if ($this->actions()->isPressed('left')) {
            $this->selected = ($this->selected + count(self::KEYS) - 1) % count(self::KEYS);
        } elseif ($this->actions()->isPressed('right')) {
            $this->selected = ($this->selected + 1) % count(self::KEYS);
        } elseif ($this->actions()->isPressed('up')) {
            $this->selected = max(0, $this->selected - 10);
        } elseif ($this->actions()->isPressed('down')) {
            $this->selected = min(count(self::KEYS) - 1, $this->selected + 10);
        } elseif ($this->actions()->isPressed('confirm')) {
            $this->activateKey();
        }
    }

    private function append(string $text): void
    {
        // Strip controls, preserve spaces, accents and punctuation for IGN search.
        $text = preg_replace('/[\x00-\x1f\x7f]/u', '', $text) ?? '';
        if ($text === '' || preg_match('//u', $text) !== 1) {
            return;
        }
        if (strlen($this->address . $text) <= 180) {
            $this->address .= $text;
            $this->message = 'ENTER / GO TO START FLIGHT';
        }
    }

    private function backspace(): void
    {
        $this->address = preg_replace('/.$/us', '', $this->address) ?? '';
    }

    private function activateKey(): void
    {
        $key = self::KEYS[$this->selected];
        if ($key === 'GO') {
            $this->submit();
        } elseif ($key === 'DEL') {
            $this->backspace();
        } else {
            $this->append($key === 'SPACE' ? ' ' : $key);
        }
    }

    private function submit(): void
    {
        $address = trim($this->address);
        if (strlen($address) < 3) {
            $this->message = 'ENTER AT LEAST THREE CHARACTERS';
            return;
        }
        $this->scenes()->switchTo(GeoFlightScene::class, ['address' => $address], false);
    }

    private function keyAt(float $x, float $y): ?int
    {
        if ($y >= 198 && $y < 342 && $x >= 10 && $x < 310) {
            $col = (int)floor(($x - 10) / 30);
            $row = (int)floor(($y - 198) / 36);
            return $row * 10 + $col;
        }
        if ($y >= 353 && $y < 392 && $x >= 14 && $x < 306) {
            return 40 + min(2, (int)floor(($x - 14) / (292 / 3)));
        }
        return null;
    }

    private function label(string $value): string
    {
        // The default game font is ASCII bitmap; keep real UTF-8 for the API.
        $value = strtr($value, [
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e', 'É'=>'E','È'=>'E',
            'à'=>'a','â'=>'a','ä'=>'a','À'=>'A','ù'=>'u','û'=>'u',
            'ç'=>'c','Ç'=>'C','ô'=>'o','ö'=>'o','î'=>'i','ï'=>'i',
            'œ'=>'oe','æ'=>'ae',
        ]);
        return preg_replace('/[^\x20-\x7e]/', '?', $value) ?? '';
    }

    public function render(Renderer $r): void
    {
        $r->clear([18, 36, 44]);
        $r->fillRect(9, 21, 302, 440, [33, 57, 63]);
        $this->font()->draw($r, 'SURVOL A L ADRESSE', 160, 42, 2, [251, 234, 188], 'center');
        $this->font()->draw($r, 'ADRESSE POSTALE / COMMUNE', 20, 82, 1, [238, 232, 207]);
        $r->fillRect(14, 100, 292, 68, [8, 22, 31]);
        $r->drawRect(14, 100, 292, 68, [228, 200, 129]);
        $display = $this->label($this->address);
        $last = substr($display, -66);
        $first = substr($last, 0, 33);
        $second = substr($last, 33, 33);
        $this->font()->draw($r, $first === '' ? 'VILLE, RUE, CODE POSTAL...' : $first, 21, 112, 1, [255, 251, 224]);
        $this->font()->draw($r, $second . ( ((int) ($this->engine->time() * 2) % 2) === 0 ? '_' : ''), 21, 136, 1, [255, 251, 224]);
        $this->font()->draw($r, substr($this->message, 0, 40), 160, 177, 1, [220, 226, 211], 'center');

        foreach (self::KEYS as $i => $key) {
            if ($i < 40) {
                $x = 10 + ($i % 10) * 30;
                $y = 198 + intdiv($i, 10) * 36;
                $w = 29;
                $h = 34;
            } else {
                $x = 14 + ($i - 40) * (292 / 3);
                $y = 353;
                $w = 292 / 3 - 2;
                $h = 38;
            }
            $selected = $this->selected === $i;
            $r->fillRect($x, $y, $w, $h, $selected ? [227, 197, 119] : [12, 30, 39]);
            $this->font()->draw($r, $key, $x + $w / 2, $y + 10, 1, $selected ? [15, 25, 29] : [240, 236, 209], 'center');
        }

        $this->font()->draw($r, 'FLECHES: TOUCHES  ENTREE: VOLER', 160, 411, 1, [220, 219, 192], 'center');
        $this->font()->draw($r, 'ECHAP: RETOUR', 160, 435, 1, [220, 219, 192], 'center');
    }
}
