<?php

namespace JumPHP\Audio;

use JumPHP\Kernel\Application;

/**
 * Positional sound ($this->engine->audio3d()): the PHP-OpenAL extension (Ponup/php-openal). Desktop: OpenAL
 * Soft (PulseAudio / PipeWire); browser: emscripten's OpenAL on Web Audio. Sounds are placed in the game's 3D
 * space (same units as the world: metres, tiles...) and heard from the listener (usually the camera).
 * SoundManager stays the tool for music and plain sound effects; this one is for "where does it come from".
 *
 *   $a = $this->engine->audio3d();
 *   $a->load('engine', 'assets/sfx/engine.wav');          // WAV, any rate; stereo is mixed down (mono = positional)
 *   $a->synth('beep', Synth::tone(880, 0.2));             // or generated
 *   $a->listener([$camX, $camY, $camZ], [0, 0, 1]);       // each frame: position, forward (up = +y)
 *   $voice = $a->play('engine', [$x, 0, $z], loop: true); // a voice id
 *   $a->move($voice, [$x, 0, $z]);                        // follow the car
 *   $a->stop($voice);
 *
 * The volume follows SoundManager (sfx volume, mute: hidden tab, options). Without a device or the extension
 * (headless runs) isAvailable() is false and every call does nothing.
 */
class Audio3d
{
    /** Voices playing at the same time. */
    public const VOICES = 16;

    /** @var resource|object|false|null */
    private $device = null;
    /** @var resource|object|null */
    private $context = null;
    /** @var array<string, resource|object> */
    private array $buffers = [];
    /** @var array<int, resource|object> */
    private array $sources = [];
    /** @var array<int, int> voice id per source slot */
    private array $voices = [];
    private int $nextVoice = 1;
    private float $refDistance = 2.0;
    private float $rolloff = 1.0;
    private float $maxDistance = 60.0;

    public function __construct(private SoundManager $sound)
    {
    }

    public function isAvailable(): bool
    {
        if ($this->device === null) {
            $this->device = false;
            if (extension_loaded('openal') && ($device = @openal_device_open())) {
                $context = openal_context_create($device);
                if ($context && openal_context_current($context)) {
                    [$this->device, $this->context] = [$device, $context];
                    openal_listener_set(AL_ORIENTATION, [0.0, 0.0, -1.0, 0.0, 1.0, 0.0]);
                } else {
                    Application::stderr("[audio3d] no OpenAL context\n");
                }
            }
        }
        return $this->device !== false;
    }

    /** How the volume fades with the distance: full until $reference, half at 2 x $reference (rolloff 1)... silent after $max. */
    public function setDistance(float $reference, float $rolloff = 1.0, float $max = 60.0): void
    {
        [$this->refDistance, $this->rolloff, $this->maxDistance] = [$reference, $rolloff, $max];
    }

    /** A WAV file of the game (8 / 16-bit PCM). */
    public function load(string $name, string $path): bool
    {
        $file = \JumPHP\Kernel\Platform::isAbsolutePath($path) ? $path : (\defined('JUMPHP_ROOT') ? JUMPHP_ROOT . '/' : '') . $path;
        $data = @file_get_contents($file);
        return $data !== false && $this->synth($name, $data);
    }

    /** A sound from WAV bytes (Synth::tone(), sweep()...). */
    public function synth(string $name, string $wav): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $pcm = self::monoPcm($wav);
        if ($pcm === null) {
            Application::stderr("[audio3d] $name: not a PCM WAV\n");
            return false;
        }
        [$rate, $samples] = $pcm;
        $buffer = openal_buffer_create();
        openal_buffer_data($buffer, AL_FORMAT_MONO16, $samples, $rate);
        $this->buffers[$name] = $buffer;
        return true;
    }

    public function has(string $name): bool
    {
        return isset($this->buffers[$name]);
    }

    /**
     * Plays a sound at $position ([x, y, z]). @return int voice id (0: not played)
     */
    public function play(string $name, array $position, float $gain = 1.0, float $pitch = 1.0, bool $loop = false): int
    {
        if (!isset($this->buffers[$name]) || $this->sound->isMuted()) {
            return 0;
        }
        $slot = $this->freeSlot();
        $source = $this->sources[$slot];
        openal_source_stop($source);
        openal_source_set($source, AL_BUFFER, $this->buffers[$name]);
        openal_source_set($source, AL_POSITION, self::vec($position));
        openal_source_set($source, AL_GAIN, max(0.0, $gain));
        openal_source_set($source, AL_PITCH, max(0.05, $pitch));
        openal_source_set($source, AL_LOOPING, $loop ? 1 : 0);
        openal_source_set($source, AL_REFERENCE_DISTANCE, $this->refDistance);
        openal_source_set($source, AL_ROLLOFF_FACTOR, $this->rolloff);
        openal_source_set($source, AL_MAX_DISTANCE, $this->maxDistance);
        openal_source_play($source);
        return $this->voices[$slot] = $this->nextVoice++;
    }

    /** Moves a playing voice (and its velocity, for the Doppler effect). */
    public function move(int $voice, array $position, ?array $velocity = null): void
    {
        $source = $this->source($voice);
        if ($source !== null) {
            openal_source_set($source, AL_POSITION, self::vec($position));
            if ($velocity !== null) {
                openal_source_set($source, AL_VELOCITY, self::vec($velocity));
            }
        }
    }

    public function setGain(int $voice, float $gain): void
    {
        $source = $this->source($voice);
        if ($source !== null) {
            openal_source_set($source, AL_GAIN, max(0.0, $gain));
        }
    }

    public function setPitch(int $voice, float $pitch): void
    {
        $source = $this->source($voice);
        if ($source !== null) {
            openal_source_set($source, AL_PITCH, max(0.05, $pitch));
        }
    }

    public function isPlaying(int $voice): bool
    {
        $source = $this->source($voice);
        return $source !== null && openal_source_get($source, AL_SOURCE_STATE) === AL_PLAYING;
    }

    public function stop(int $voice): void
    {
        $source = $this->source($voice);
        if ($source !== null) {
            openal_source_stop($source);
        }
    }

    public function stopAll(): void
    {
        foreach ($this->sources as $source) {
            openal_source_stop($source);
        }
    }

    /**
     * Where the sounds are heard from: position, forward direction and up (default +y), velocity (Doppler).
     * @param float[] $position @param float[] $forward @param float[] $up
     */
    public function listener(array $position, array $forward = [0, 0, -1], array $up = [0, 1, 0], ?array $velocity = null): void
    {
        if (!$this->isAvailable()) {
            return;
        }
        openal_listener_set(AL_POSITION, self::vec($position));
        openal_listener_set(AL_ORIENTATION, [...self::vec($forward), ...self::vec($up)]);
        if ($velocity !== null) {
            openal_listener_set(AL_VELOCITY, self::vec($velocity));
        }
    }

    /** @internal Engine, each frame: the master volume follows SoundManager (sfx volume, mute). */
    public function sync(): void
    {
        if ($this->device) {
            openal_listener_set(AL_GAIN, $this->sound->isMuted() ? 0.0 : $this->sound->getSfxVolume());
        }
    }

    public function close(): void
    {
        if (!$this->device) {
            return;
        }
        $this->stopAll();
        foreach ($this->sources as $source) {
            openal_source_destroy($source);
        }
        foreach ($this->buffers as $buffer) {
            openal_buffer_destroy($buffer);
        }
        [$this->sources, $this->buffers, $this->voices] = [[], [], []];
        openal_context_destroy($this->context);
        openal_device_close($this->device);
        $this->device = false;
    }

    // ---------------------------------------------------------------------------------------------

    /** @return resource|object|null the source of a voice still playing it */
    private function source(int $voice)
    {
        $slot = array_search($voice, $this->voices, true);
        return $slot === false || $voice <= 0 ? null : $this->sources[$slot];
    }

    private function freeSlot(): int
    {
        foreach ($this->voices as $slot => $voice) {
            if (openal_source_get($this->sources[$slot], AL_SOURCE_STATE) !== AL_PLAYING) {
                return $slot;
            }
        }
        if (count($this->sources) < self::VOICES) {
            $this->sources[] = openal_source_create();
            return array_key_last($this->sources);
        }
        // every voice busy: the oldest one
        return (int)array_search(min($this->voices), $this->voices, true);
    }

    /** @return float[] */
    private static function vec(array $v): array
    {
        return [(float)($v[0] ?? 0), (float)($v[1] ?? 0), (float)($v[2] ?? 0)];
    }

    /**
     * WAV (PCM 8 / 16-bit, mono or stereo) to mono 16-bit samples: OpenAL only places mono sounds in space.
     * @return array{0: int, 1: string}|null [rate, samples]
     */
    public static function monoPcm(string $wav): ?array
    {
        if (strlen($wav) < 44 || substr($wav, 0, 4) !== 'RIFF' || substr($wav, 8, 4) !== 'WAVE') {
            return null;
        }
        $format = null;
        $data = null;
        for ($at = 12; $at + 8 <= strlen($wav);) {
            $id = substr($wav, $at, 4);
            $size = unpack('V', $wav, $at + 4)[1];
            if ($id === 'fmt ') {
                $format = unpack('vcode/vchannels/Vrate/Vbytes/valign/vbits', $wav, $at + 8);
            } elseif ($id === 'data') {
                $data = substr($wav, $at + 8, $size);
            }
            $at += 8 + $size + ($size & 1);
        }
        if ($format === null || $data === null || $format['code'] !== 1 || !in_array($format['bits'], [8, 16], true)) {
            return null;
        }
        $channels = max(1, $format['channels']);
        if ($format['bits'] === 16 && $channels === 1) {
            return [$format['rate'], $data];
        }
        $values = $format['bits'] === 16 ? array_values(unpack('s*', $data)) : array_map(fn (int $b) => ($b - 128) << 8, array_values(unpack('C*', $data)));
        $mono = [];
        for ($i = 0, $n = count($values) - $channels + 1; $i < $n; $i += $channels) {
            $sum = 0;
            for ($c = 0; $c < $channels; $c++) {
                $sum += $values[$i + $c];
            }
            $mono[] = intdiv($sum, $channels) & 0xFFFF;
        }
        return [$format['rate'], $mono === [] ? '' : pack('v*', ...$mono)];
    }
}
