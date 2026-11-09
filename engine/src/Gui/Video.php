<?php

namespace JumPHP\Gui;

use JumPHP\Kernel\Engine;
use JumPHP\Renderer\Renderer;

/**
 * A video: `src` = a film put in assets/videos/ (assets/videos/intro.mp4: .mp4, .webm, .mov, .mkv, .avi), or a folder of
 * pictures, one per frame, in name order (assets/videos/intro/00001.jpg...). `fps`, `audio` (a sound file played with
 * it, default the folder's audio.ogg), `loop`, `autoplay`, `controls` (a play / pause button over it).
 * The same everywhere (desktop, browser, phones), SDL decoding no video stream: bin/jumphp cuts each film of
 * assets/videos/ into its pictures and its sound (assets/videos/intro/, made again when the film changes) before run,
 * test, shot, the builds and the exports, which leave the film itself out. `bin/jumphp video <app> film.mp4` does it
 * by hand for a film from elsewhere.
 * From the code: play(), pause(), stop(), seek($seconds), isPlaying(), duration(), position().
 * Events: end (the last frame, without loop), click.
 *
 * @property string $src
 * @property float $fps
 */
class Video extends Widget
{
    public const DEFAULTS = ['src' => '', 'fps' => 25, 'audio' => '', 'loop' => false, 'autoplay' => true, 'controls' => true, 'fit' => 'contain',
        'background' => '#000000'];

    /** @var list<string>|null */
    private ?array $frames = null;
    private string $framesOf = '';
    private float $time = 0;
    private bool $playing = false;
    private bool $started = false;
    private int $shown = -1;
    /** @var array<string, true> sound tracks loaded */
    private static array $musics = [];
    /** @var list<string> frames loaded, oldest first */
    private array $cache = [];

    public static function events(): array
    {
        return ['end', 'click'];
    }

    /** The folder of the pictures: the film's own (assets/videos/intro.mp4 -> assets/videos/intro), or src. */
    public function folder(): string
    {
        $src = rtrim((string)$this->props['src'], '/');
        return preg_match(self::FILM, $src) ? (string)preg_replace(self::FILM, '', $src) : $src;
    }

    /** a film, cut into pictures by bin/jumphp */
    public const FILM = '/\.(mp4|webm|mov|mkv|avi|m4v)$/i';

    /** @return list<string> the files of the frames */
    public function frames(): array
    {
        $src = $this->folder();
        if ($this->frames === null || $this->framesOf !== $src) {
            $dir = $this->path($src);
            $files = $dir !== '' && is_dir($dir) ? (glob(rtrim($dir, '/') . '/*.{png,jpg,jpeg,webp,PNG,JPG}', GLOB_BRACE) ?: []) : [];
            sort($files, SORT_NATURAL);
            $this->frames = $files;
            $this->framesOf = $src;
            $this->shown = -1;
        }
        return $this->frames;
    }

    private function path(string $relative): string
    {
        if ($relative === '' || str_starts_with($relative, '/')) {
            return $relative;
        }
        try {
            return Engine::get()->config->path($relative);
        } catch (\LogicException) {
            return $relative;
        }
    }

    public function duration(): float
    {
        return count($this->frames()) / max(1, (float)$this->props['fps']);
    }

    public function position(): float
    {
        return $this->time;
    }

    public function isPlaying(): bool
    {
        return $this->playing;
    }

    public function play(): void
    {
        if ($this->time >= $this->duration()) {
            $this->time = 0;
        }
        $this->playing = true;
        $this->started = true;
        $this->sound(true);
    }

    public function pause(): void
    {
        $this->playing = false;
        $this->sound(false);
    }

    public function stop(): void
    {
        $this->pause();
        $this->time = 0;
    }

    public function seek(float $seconds): void
    {
        $this->time = max(0, min($seconds, $this->duration()));
    }

    /** The sound track: SoundManager's music (it has no seek: it starts again from the beginning on a seek). */
    private function sound(bool $on): void
    {
        $audio = (string)$this->props['audio'];
        if ($audio === '' && $this->folder() !== '' && is_file($this->path($this->folder() . '/audio.ogg'))) {
            $audio = $this->folder() . '/audio.ogg';
        }
        if ($audio === '') {
            return;
        }
        try {
            $sound = Engine::get()->sound;
        } catch (\LogicException) {
            return;
        }
        $name = 'video:' . md5($audio);
        if ($on) {
            if (!isset(self::$musics[$name])) {
                $sound->loadMusic($name, $audio);
                self::$musics[$name] = true;
            }
            $sound->playMusic($name, (bool)$this->props['loop']);
        } else {
            $sound->stopMusic();
        }
    }

    public function update(float $dt): void
    {
        if (!$this->started && $this->props['autoplay'] && $this->frames() !== []) {
            $this->play();
        }
        if (!$this->playing) {
            return;
        }
        $this->time += $dt;
        if ($this->time >= $this->duration()) {
            if ($this->props['loop']) {
                $this->time = fmod($this->time, max(0.001, $this->duration()));
            } else {
                $this->time = $this->duration();
                $this->playing = false;
                $this->emit('end');
            }
        }
    }

    protected function content(Theme $t, float $maxWidth, string $breakpoint): array
    {
        $first = $this->frames()[0] ?? null;
        $size = $first !== null ? (Picture::size($this->form?->sdlOrNull(), $first) ?? [320, 180]) : [320, 180];
        $w = min($t->px($size[0]), $maxWidth);
        return [$w, $w * $size[1] / max(1, $size[0])];
    }

    public function stretches(string $axis): bool
    {
        return $axis === 'width';
    }

    public function measure(Theme $t, float $maxWidth, string $breakpoint): array
    {
        [$w, $h] = parent::measure($t, $maxWidth, $breakpoint);
        if (!is_numeric($this->props['height'])) {
            $first = $this->frames()[0] ?? null;
            $size = $first !== null ? (Picture::size($this->form?->sdlOrNull(), $first) ?? [16, 9]) : [16, 9];
            $h = $maxWidth * $size[1] / max(1, $size[0]);
        }
        return [$w, $h];
    }

    public function render(Renderer $r, Theme $t): void
    {
        $r->fillRect($this->x, $this->y, $this->w, $this->h, $this->tint($t, 'background', 'text'));
        $frames = $this->frames();
        if ($frames === []) {
            $t->font()->draw($r, 'VIDEO', $this->x + $this->w / 2, $this->y + $this->h / 2 - $t->font()->height() / 2, 1, '#888888', 'center');
            return;
        }
        $index = min(count($frames) - 1, (int)floor($this->time * max(1, (float)$this->props['fps'])));
        $file = $frames[$index];
        Picture::draw($r, $this->form, $file, $this->x, $this->y, $this->w, $this->h, (string)$this->props['fit']);
        if ($index !== $this->shown) {
            $this->shown = $index;
            $this->cache[] = $file;
            // a few frames in memory, not the whole film
            while (count($this->cache) > 8) {
                Picture::forget($this->form?->sdlOrNull(), array_shift($this->cache));
            }
        }
        if ($this->props['controls'] && (!$this->playing || $this->hovered)) {
            $s = $t->px(44);
            $cx = $this->x + $this->w / 2;
            $cy = $this->y + $this->h / 2;
            $r->fillCircle($cx, $cy, $s / 2, [0, 0, 0, 150]);
            if ($this->playing) {
                $r->fillRect($cx - $s * 0.18, $cy - $s * 0.2, $s * 0.12, $s * 0.4, '#ffffff');
                $r->fillRect($cx + $s * 0.06, $cy - $s * 0.2, $s * 0.12, $s * 0.4, '#ffffff');
            } else {
                for ($i = 0, $n = (int)($s * 0.4); $i < $n; $i++) {
                    $half = ($n - $i) / 2;
                    $r->fillRect($cx - $s * 0.12 + $i, $cy - $half, 1, 2 * $half, '#ffffff');
                }
            }
            // where it is
            $r->fillRect($this->x, $this->y + $this->h - $t->px(3), $this->w, $t->px(3), [255, 255, 255, 60]);
            $r->fillRect($this->x, $this->y + $this->h - $t->px(3), $this->w * $this->time / max(0.001, $this->duration()), $t->px(3), $t->color('accent'));
        }
    }

    protected function click(Pointer $p): void
    {
        if ($this->props['controls']) {
            $this->playing ? $this->pause() : $this->play();
        }
        parent::click($p);
    }
}
