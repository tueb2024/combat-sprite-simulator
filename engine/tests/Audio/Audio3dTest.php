<?php

namespace JumPHP\Tests\Audio;

use JumPHP\Audio\Audio3d;
use JumPHP\Audio\SoundManager;
use JumPHP\Audio\Synth;
use PHPUnit\Framework\TestCase;

final class Audio3dTest extends TestCase
{
    public function testSynthWavIsAlreadyMono16(): void
    {
        $wav = Synth::tone(440, 0.1);
        [$rate, $pcm] = Audio3d::monoPcm($wav);
        self::assertSame(Synth::RATE, $rate);
        self::assertSame(strlen($wav) - 44, strlen($pcm));
    }

    public function testStereoAnd8BitAreMixedDown(): void
    {
        // stereo 16-bit: (1000, 3000) -> 2000, (-4000, 0) -> -2000
        $data = pack('v*', 1000, 3000, -4000 & 0xFFFF, 0);
        $wav = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 2, 8000, 32000, 4, 16)
            . 'data' . pack('V', strlen($data)) . $data;
        [$rate, $pcm] = Audio3d::monoPcm($wav);
        self::assertSame(8000, $rate);
        self::assertSame([2000, -2000], array_values(unpack('s*', $pcm)));
        // 8-bit mono: 128 = silence, 255 = loud
        $data8 = pack('C*', 128, 255);
        $wav8 = 'RIFF' . pack('V', 36 + 2) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 2) . $data8;
        self::assertSame([0, 127 << 8], array_values(unpack('s*', Audio3d::monoPcm($wav8)[1])));
        self::assertNull(Audio3d::monoPcm('not a wav'));
    }

    public function testPlaysThroughOpenAlWhenThereIsADevice(): void
    {
        putenv('ALSOFT_DRIVERS=null');
        $audio = new Audio3d($this->createMock(SoundManager::class));
        if (!$audio->isAvailable()) {
            self::markTestSkipped('no OpenAL extension / device');
        }
        self::assertTrue($audio->synth('beep', Synth::tone(880, 0.5)));
        $audio->listener([0, 0, 0], [0, 0, -1]);
        $voice = $audio->play('beep', [3, 0, -2]);
        self::assertGreaterThan(0, $voice);
        self::assertTrue($audio->isPlaying($voice));
        $audio->move($voice, [-3, 0, -2], [1, 0, 0]);
        $audio->stop($voice);
        self::assertFalse($audio->isPlaying($voice));
        self::assertSame(0, $audio->play('missing', [0, 0, 0]));
        $audio->close();
    }
}
