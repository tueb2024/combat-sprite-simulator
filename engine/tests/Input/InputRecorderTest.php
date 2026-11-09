<?php

namespace JumPHP\Tests\Input;

use JumPHP\Input\ActionMap;
use JumPHP\Input\Gamepad;
use JumPHP\Input\InputRecorder;
use JumPHP\Input\Keyboard;
use JumPHP\Input\Mouse;
use JumPHP\Input\Touch;
use PHPUnit\Framework\TestCase;

final class InputRecorderTest extends TestCase
{
    public function testWhatIsHeldAndLetGoIsRecordedInTheFormatOfJumphpInput(): void
    {
        $file = sys_get_temp_dir() . '/jumphp-input-' . bin2hex(random_bytes(4)) . '.json';
        $actions = new ActionMap(new Keyboard(), new Gamepad(), new Touch(), new Mouse());
        $actions->load(['jump' => ['Space'], 'right' => ['Right']]);
        $recorder = new InputRecorder($file);
        $actions->hold('jump');
        $recorder->observe(10, $actions);
        $recorder->observe(11, $actions);               // still held: nothing new
        $actions->hold('right');
        $recorder->observe(20, $actions);
        $actions->hold('jump', false);
        $recorder->observe(30, $actions);
        self::assertSame([[10, 'jump', 'hold'], [20, 'right', 'hold'], [30, 'jump', 'release']], $recorder->events());
        self::assertSame($recorder->events(), json_decode((string)file_get_contents($file), true));
    }

    public function testATapShorterThanAFrameIsARecordedPress(): void
    {
        $file = sys_get_temp_dir() . '/jumphp-input-' . bin2hex(random_bytes(4)) . '.json';
        $actions = new ActionMap(new Keyboard(), new Gamepad(), new Touch(), new Mouse());
        $actions->load(['fire' => ['Space']]);
        $recorder = new InputRecorder($file);
        $actions->press('fire');                        // pressed this frame, never held
        $recorder->observe(7, $actions);
        $actions->endFrame();
        $recorder->observe(8, $actions);                // nothing the next frame
        self::assertSame([[7, 'fire']], $recorder->events());
    }
}
