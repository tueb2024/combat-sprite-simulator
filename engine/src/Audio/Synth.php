<?php

namespace JumPHP\Audio;

/**
 * Retro sound effects generated in PHP: no audio file needed. Returns WAV bytes (16-bit mono PCM);
 * SoundManager::synth() writes them to a temp file and loads them.
 *
 *   $sound->synth('jump',  Synth::sweep(300, 900, 0.15, 'square'));
 *   $sound->synth('hit',   Synth::tone(220, 0.08, 'square'));
 *   $sound->synth('boom',  Synth::noise(0.4, 0.9));
 *   $sound->synth('coin',  Synth::sequence([[988, 0.06], [1319, 0.18]], 'square'));
 *
 * Waves: square, triangle, sine, saw, noise. Volume 0..1. A short attack/release avoids clicks.
 */
final class Synth
{
    public const RATE = 22050;

    public static function tone(float $freq, float $seconds, string $wave = 'square', float $volume = 0.5): string
    {
        return self::wav(self::render(fn (float $t) => $freq, $seconds, $wave, $volume));
    }

    /** Frequency glides from $from to $to Hz (jumps, lasers, power-ups, falling). */
    public static function sweep(float $from, float $to, float $seconds, string $wave = 'square', float $volume = 0.5): string
    {
        return self::wav(self::render(fn (float $t) => $from + ($to - $from) * ($t / $seconds), $seconds, $wave, $volume));
    }

    /** White noise with a fading envelope (explosions, hits). */
    public static function noise(float $seconds, float $volume = 0.5): string
    {
        return self::wav(self::render(fn (float $t) => 0.0, $seconds, 'noise', $volume, true));
    }

    /** Notes one after the other: [[freq Hz, seconds], ...]; freq 0 = silence. Jingles. */
    public static function sequence(array $notes, string $wave = 'square', float $volume = 0.5): string
    {
        $samples = [];
        foreach ($notes as [$freq, $seconds]) {
            $samples = array_merge($samples, $freq > 0
                ? self::render(fn (float $t) => (float)$freq, (float)$seconds, $wave, $volume)
                : array_fill(0, (int)($seconds * self::RATE), 0));
        }
        return self::wav($samples);
    }

    /** @return int[] 16-bit samples */
    private static function render(callable $frequency, float $seconds, string $wave, float $volume, bool $decay = false): array
    {
        $count = max(1, (int)($seconds * self::RATE));
        $fade = min(200, intdiv($count, 4));
        $phase = 0.0;
        $samples = [];
        $amplitude = 32767 * max(0.0, min(1.0, $volume));
        for ($i = 0; $i < $count; $i++) {
            $t = $i / self::RATE;
            $phase += $frequency($t) / self::RATE;
            $p = $phase - floor($phase);
            $v = match ($wave) {
                'sine' => sin(2 * M_PI * $p),
                'triangle' => 4 * abs($p - 0.5) - 1,
                'saw' => 2 * $p - 1,
                'noise' => mt_rand() / mt_getrandmax() * 2 - 1,
                default => $p < 0.5 ? 1.0 : -1.0,
            };
            $env = 1.0;
            if ($i < $fade) {
                $env = $i / $fade;
            } elseif ($i > $count - $fade) {
                $env = ($count - $i) / $fade;
            }
            if ($decay) {
                $env *= 1 - $i / $count;
            }
            $samples[] = (int)($v * $env * $amplitude);
        }
        return $samples;
    }

    /** @param int[] $samples */
    public static function wav(array $samples): string
    {
        $data = pack('v*', ...array_map(fn (int $s) => $s & 0xFFFF, $samples));
        $size = strlen($data);
        return 'RIFF' . pack('V', 36 + $size) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, self::RATE, self::RATE * 2, 2, 16)
            . 'data' . pack('V', $size) . $data;
    }
}
