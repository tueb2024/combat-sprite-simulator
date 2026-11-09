<?php

namespace JumPHP\Audio;

/**
 * Sound effects and music through SDL_mixer. Everything is by name:
 *
 *   $sound->loadSfx('jump', 'assets/sfx/jump.wav');        // or ->synth('jump', Synth::sweep(...))
 *   $sound->playSfx('jump');
 *   $sound->loadMusic('level1', 'assets/music/level1.ogg', 'assets/music/level1-intro.ogg'); // intro then loop
 *   $sound->playMusic('level1');
 *
 * Without an audio device (servers, CI, some browsers before a click) every call is a silent no-op:
 * a game never crashes because of sound. Volumes are 0..1 (saved in the options by the engine).
 * Browsers only start audio after a user gesture: the page resumes it on the first key / click.
 */
class SoundManager
{
    private bool $enabled = false;
    /** @var array<string, mixed> */
    private array $chunks = [];
    /** @var array<string, int> channel of the last play of each sfx */
    private array $channels = [];
    /** @var array<string, float> time of the last play (throttle) */
    private array $lastPlayed = [];
    /** @var array<string, array{loop: mixed, intro: mixed}> */
    private array $musics = [];
    private ?string $currentMusic = null;
    /** @var array<string, array{0: string, 1: ?string, 2: bool}> file, intro file, music? of each loaded sound (hot reload) */
    private array $sources = [];

    /** JUMPHP_AUDIO_LOG=<folder>: what is played and when, for a film of the game (AudioLog), also without an audio device */
    private AudioLog|false|null $log = null;
    private ?string $loggedMusic = null;
    private bool $inIntro = false;
    private float $sfxVolume = 1.0;
    private float $musicVolume = 0.7;
    private bool $muted = false;
    /** Minimum seconds between two plays of the same sfx (0 = no limit). */
    public float $throttle = 0.04;

    public function init(): bool
    {
        if (!function_exists('Mix_OpenAudio')) {
            return false;
        }
        // 0x08 = MIX_INIT_MP3, 0x10 = MIX_INIT_OGG
        @\Mix_Init(0x08 | 0x10);
        // 2048 samples (46 ms): a smaller buffer underruns in the browser when a frame is slow
        if (\Mix_OpenAudio(44100, \MIX_DEFAULT_FORMAT, 2, 2048) !== 0) {
            return $this->enabled = false;
        }
        \Mix_AllocateChannels(16);
        $this->enabled = true;
        $this->applyVolumes();
        return true;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function loadSfx(string $name, string $path): void
    {
        $this->audioLog()?->register($name, $this->resolve($path));
        $this->loadSfxFile($name, $path);
    }

    private function loadSfxFile(string $name, string $path): void
    {
        if (!$this->enabled) {
            return;
        }
        $file = $this->resolve($path);
        $chunk = \Mix_LoadWAV($file);
        if (!$chunk) {
            throw new \RuntimeException("Mix_LoadWAV($file) failed: " . \Mix_GetError());
        }
        $this->chunks[$name] = $chunk;
        $this->sources[$name] = [$file, null, false];
    }

    /**
     * The sounds and musics of these files (absolute paths) loaded again: a sound changed while the game runs (hot
     * reload). A music playing plays again. @return int how many
     *
     * @param string[] $files
     */
    public function reloadFiles(array $files): int
    {
        $count = 0;
        foreach ($this->sources as $name => [$file, $intro, $isMusic]) {
            if (!in_array($file, $files, true) && ($intro === null || !in_array($intro, $files, true))) {
                continue;
            }
            if ($isMusic && isset($this->musics[$name])) {
                $wasPlaying = $this->currentMusic === $name;
                if ($wasPlaying) {
                    \Mix_HaltMusic();
                    $this->currentMusic = null;
                }
                \Mix_FreeMusic($this->musics[$name]['loop']);
                if ($this->musics[$name]['intro']) {
                    \Mix_FreeMusic($this->musics[$name]['intro']);
                }
                $this->loadMusic($name, $file, $intro);
                if ($wasPlaying) {
                    $this->playMusic($name);
                }
            } elseif (isset($this->chunks[$name])) {
                \Mix_FreeChunk($this->chunks[$name]);
                $this->loadSfx($name, $file);
            }
            $count++;
        }
        return $count;
    }

    /** Registers a generated sound (Synth::*). */
    public function synth(string $name, string $wavBytes): void
    {
        $this->audioLog()?->registerBytes($name, $wavBytes);
        if (!$this->enabled) {
            return;
        }
        $dir = sys_get_temp_dir() . '/jumphp-sfx';
        @mkdir($dir, 0777, true);
        $file = $dir . '/' . preg_replace('/[^\w-]/', '_', $name) . '.wav';
        file_put_contents($file, $wavBytes);
        $this->loadSfxFile($name, $file);
    }

    public function hasSfx(string $name): bool
    {
        return isset($this->chunks[$name]);
    }

    /** @param bool $restart stop the previous play of the same sound first (no pile-up) */
    public function playSfx(string $name, int $loops = 0, bool $restart = true): void
    {
        if (!$this->muted) {
            $this->audioLog()?->sfx($this->clock(), $name, $this->sfxVolume, $loops);
        }
        if (!$this->enabled || $this->muted || !isset($this->chunks[$name])) {
            return;
        }
        $now = microtime(true);
        if ($this->throttle > 0 && $now - ($this->lastPlayed[$name] ?? 0) < $this->throttle) {
            return;
        }
        $this->lastPlayed[$name] = $now;
        if ($restart && isset($this->channels[$name]) && \Mix_Playing($this->channels[$name])) {
            \Mix_HaltChannel($this->channels[$name]);
        }
        $channel = \Mix_PlayChannel(-1, $this->chunks[$name], $loops);
        if ($channel >= 0) {
            $this->channels[$name] = $channel;
        }
    }

    public function stopSfx(string $name): void
    {
        if ($this->enabled && isset($this->channels[$name])) {
            \Mix_HaltChannel($this->channels[$name]);
            unset($this->channels[$name]);
        }
    }

    /** $introPath: played once before the loop starts (update() chains them). */
    public function loadMusic(string $name, string $path, ?string $introPath = null): void
    {
        $this->audioLog()?->register($name, $this->resolve($path));
        if (!$this->enabled) {
            return;
        }
        $loop = \Mix_LoadMUS($this->resolve($path));
        if (!$loop) {
            throw new \RuntimeException("Mix_LoadMUS($path) failed: " . \Mix_GetError());
        }
        $intro = $introPath !== null ? \Mix_LoadMUS($this->resolve($introPath)) : null;
        $this->musics[$name] = ['loop' => $loop, 'intro' => $intro ?: null];
        $this->sources[$name] = [$this->resolve($path), $introPath !== null ? $this->resolve($introPath) : null, true];
    }

    public function playMusic(string $name, bool $loop = true): void
    {
        if (!$this->muted && $this->loggedMusic !== $name) {
            $this->loggedMusic = $name;
            $this->audioLog()?->music($this->clock(), $name, $this->musicVolume, $loop);
        }
        if (!$this->enabled || !isset($this->musics[$name])) {
            return;
        }
        if ($this->currentMusic === $name && \Mix_PlayingMusic()) {
            return;
        }
        $this->currentMusic = $name;
        $music = $this->musics[$name];
        if ($music['intro'] !== null && $loop) {
            $this->inIntro = true;
            \Mix_PlayMusic($music['intro'], 1);
        } else {
            $this->inIntro = false;
            \Mix_PlayMusic($music['loop'], $loop ? -1 : 1);
        }
    }

    public function stopMusic(): void
    {
        if ($this->loggedMusic !== null) {
            $this->loggedMusic = null;
            $this->audioLog()?->stopMusic($this->clock());
        }
        if ($this->enabled) {
            \Mix_HaltMusic();
        }
        $this->currentMusic = null;
        $this->inIntro = false;
    }

    public function getCurrentMusic(): ?string
    {
        return $this->currentMusic;
    }

    /** Once per frame (the engine calls it): intro -> loop. */
    public function update(): void
    {
        if ($this->enabled && $this->inIntro && !\Mix_PlayingMusic() && $this->currentMusic !== null) {
            $this->inIntro = false;
            \Mix_PlayMusic($this->musics[$this->currentMusic]['loop'], -1);
        }
    }

    public function setSfxVolume(float $volume): void
    {
        $this->sfxVolume = max(0.0, min(1.0, $volume));
        $this->applyVolumes();
    }

    public function setMusicVolume(float $volume): void
    {
        $this->musicVolume = max(0.0, min(1.0, $volume));
        $this->applyVolumes();
    }

    public function getSfxVolume(): float { return $this->sfxVolume; }
    public function getMusicVolume(): float { return $this->musicVolume; }

    public function setMuted(bool $muted): void
    {
        $this->muted = $muted;
        $this->applyVolumes();
    }

    public function isMuted(): bool
    {
        return $this->muted;
    }

    private function audioLog(): ?AudioLog
    {
        if ($this->log === null) {
            $dir = (string)\JumPHP\Kernel\Platform::setting('JUMPHP_AUDIO_LOG', '');
            $this->log = $dir !== '' ? new AudioLog($dir) : false;
        }
        return $this->log ?: null;
    }

    /** The game's clock (seconds), what the film's sound is placed by. */
    private function clock(): float
    {
        return \JumPHP\Kernel\Engine::get()->time();
    }

    private function applyVolumes(): void
    {
        if (!$this->enabled) {
            return;
        }
        \Mix_Volume(-1, $this->muted ? 0 : (int)round($this->sfxVolume * 128));
        \Mix_VolumeMusic($this->muted ? 0 : (int)round($this->musicVolume * 128));
    }

    private function resolve(string $path): string
    {
        if (\JumPHP\Kernel\Platform::isAbsolutePath($path)) {
            return $path;
        }
        $root = defined('JUMPHP_ROOT') ? JUMPHP_ROOT : getcwd();
        return rtrim((string)$root, '/') . '/' . $path;
    }

    public function close(): void
    {
        if (!$this->enabled) {
            return;
        }
        \Mix_HaltChannel(-1);
        \Mix_HaltMusic();
        foreach ($this->chunks as $chunk) {
            \Mix_FreeChunk($chunk);
        }
        foreach ($this->musics as $music) {
            \Mix_FreeMusic($music['loop']);
            if ($music['intro']) {
                \Mix_FreeMusic($music['intro']);
            }
        }
        $this->chunks = $this->musics = [];
        \Mix_CloseAudio();
        $this->enabled = false;
    }
}
