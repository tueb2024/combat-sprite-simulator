<?php

namespace JumPHP\Audio;

/**
 * What a game played and when, for a film of it (`bin/jumphp video:replay`): JUMPHP_AUDIO_LOG=<folder> makes SoundManager write
 * <folder>/audio.json: [{t, kind: "sfx"|"music"|"stop", file, volume, loop}] with the game's own clock, and, for generated sounds
 * (Synth), a copy of the WAV in <folder>/sfx/. The sound is then mixed offline from these files, no audio device needed.
 */
final class AudioLog
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @var array<string, string> name => file, for sfx and music */
    private array $files = [];

    public function __construct(private string $dir)
    {
        @mkdir("$dir/sfx", 0777, true);
        $this->save();
    }

    public function register(string $name, string $file): void
    {
        $this->files[$name] = $file;
    }

    /** A generated sound: its WAV is kept in the folder (the temporary one is not). */
    public function registerBytes(string $name, string $wavBytes): void
    {
        $file = $this->dir . '/sfx/' . preg_replace('/[^\w-]/', '_', $name) . '.wav';
        file_put_contents($file, $wavBytes);
        $this->files[$name] = $file;
    }

    public function sfx(float $time, string $name, float $volume, int $loops = 0): void
    {
        if (isset($this->files[$name])) {
            $this->push(['t' => round($time, 4), 'kind' => 'sfx', 'file' => $this->files[$name], 'volume' => round($volume, 3), 'loop' => $loops !== 0]);
        }
    }

    public function music(float $time, string $name, float $volume, bool $loop): void
    {
        if (isset($this->files[$name])) {
            $this->push(['t' => round($time, 4), 'kind' => 'music', 'file' => $this->files[$name], 'volume' => round($volume, 3), 'loop' => $loop]);
        }
    }

    public function stopMusic(float $time): void
    {
        $this->push(['t' => round($time, 4), 'kind' => 'stop', 'file' => '', 'volume' => 0.0, 'loop' => false]);
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        return $this->events;
    }

    private function push(array $event): void
    {
        $this->events[] = $event;
        $this->save();
    }

    private function save(): void
    {
        file_put_contents($this->dir . '/audio.json', json_encode($this->events, JSON_UNESCAPED_SLASHES));
    }
}
