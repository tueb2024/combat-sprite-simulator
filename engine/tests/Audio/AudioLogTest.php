<?php

namespace JumPHP\Tests\Audio;

use JumPHP\Audio\AudioLog;
use JumPHP\Audio\Synth;
use PHPUnit\Framework\TestCase;

final class AudioLogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/jumphp-audiolog-' . bin2hex(random_bytes(4));
    }

    public function testWhatIsPlayedIsWrittenWithTheGamesClock(): void
    {
        $log = new AudioLog($this->dir);
        $log->register('jump', '/game/assets/sfx/jump.wav');
        $log->registerBytes('beep', Synth::tone(440, 0.1, 'square'));
        $log->sfx(1.23456, 'jump', 0.8);
        $log->sfx(2.0, 'beep', 1.0, -1);
        $log->sfx(3.0, 'unknown', 1.0);                 // a sound nobody loaded: nothing to mix
        $log->register('theme', '/game/assets/music/theme.ogg');
        $log->music(0.5, 'theme', 0.6, true);
        $log->stopMusic(9.0);

        $events = json_decode((string)file_get_contents("$this->dir/audio.json"), true);
        self::assertSame(['sfx', 'sfx', 'music', 'stop'], array_column($events, 'kind'));
        self::assertEquals([1.2346, 2.0, 0.5, 9.0], array_column($events, 't'));
        self::assertSame('/game/assets/sfx/jump.wav', $events[0]['file']);
        self::assertTrue($events[1]['loop']);
        self::assertFileExists("$this->dir/sfx/beep.wav", 'a generated sound is kept in the folder');
        self::assertSame("$this->dir/sfx/beep.wav", $events[1]['file']);
        self::assertSame(0.6, $events[2]['volume']);
    }
}
