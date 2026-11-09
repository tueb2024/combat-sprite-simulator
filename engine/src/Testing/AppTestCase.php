<?php

namespace JumPHP\Testing;

use JumPHP\Native\Native;
use JumPHP\Tauri\App;
use JumPHP\Tauri\FakeRuntime;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

/**
 * A PHPUnit test of a desktop app (JumPHP\Tauri\App) without a window: the app boots from its folder on a
 * FakeRuntime, the test plays the page's side (invoke, send, close a window, answer a dialog) frame by frame and
 * checks what PHP answered or asked the native side to do. Runs anywhere (no FFI, no GTK).
 *
 *   final class MainTest extends AppTestCase
 *   {
 *       public function testThePageIsGreeted(): void
 *       {
 *           self::assertSame('Hello Ada', $this->invoke('greet', ['name' => 'Ada']));
 *       }
 *   }
 */
abstract class AppTestCase extends TestCase
{
    protected App $app;
    protected FakeRuntime $runtime;
    private int $nextId = 1000;

    protected function setUp(): void
    {
        Native::setProvider(null);
        Loop::set(new StreamSelectLoop());     // a fresh ReactPHP loop: no timer of an earlier test
        $this->runtime = new FakeRuntime();
        $this->app = App::boot((string)getcwd(), $this->runtime);
        $this->runtime->start([], fn () => $this->app->frame());
        $this->frames(1);
    }

    protected function tearDown(): void
    {
        Native::setProvider(null);
        App::forgetCurrent();
        Loop::stop();
    }

    /** Runs $count frames. */
    protected function frames(int $count = 1): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->runtime->frame();
        }
    }

    /** Frames for $seconds of real time (ReactPHP timers run on the clock): ~60 frames a second. */
    protected function wait(float $seconds): void
    {
        $end = microtime(true) + $seconds;
        while (microtime(true) < $end) {
            $this->frames(1);
            usleep(16000);
        }
    }

    /**
     * jumphp.invoke($name, $payload) from a page, then frames until PHP answers (at most $frames).
     * Returns the result; a rejected call throws a \RuntimeException with its message.
     */
    protected function invoke(string $name, mixed $payload = null, string $window = 'main', int $frames = 60): mixed
    {
        $id = $this->nextId++;
        $this->runtime->push(['type' => 'invoke', 'id' => $id, 'name' => $name, 'payload' => $payload, 'window' => $window]);
        for ($i = 0; $i < $frames; $i++) {
            $this->frames(1);
            foreach ($this->runtime->sentOf('invoke.resolve') as $reply) {
                if (($reply['id'] ?? null) === $id) {
                    if (!($reply['ok'] ?? false)) {
                        throw new \RuntimeException((string)($reply['result']['message'] ?? 'rejected'));
                    }
                    return $reply['result'] ?? null;
                }
            }
        }
        self::fail("no answer to invoke('$name') in $frames frames");
    }

    /** jumphp.send($name, $payload) from a page (PHP: $app->on('page:<name>')), then one frame. */
    protected function pageSend(string $name, mixed $payload = null, string $window = 'main'): void
    {
        $this->runtime->push(['type' => 'page', 'name' => $name, 'payload' => $payload, 'window' => $window]);
        $this->frames(1);
    }

    /** The page of $window loaded (Window 'loaded'). */
    protected function pageLoaded(string $window = 'main'): void
    {
        $this->pageSend('__loaded', 'tauri://localhost/', $window);
    }

    /** The user clicks the close button of $window, then one frame. */
    protected function requestClose(string $window = 'main'): void
    {
        $this->runtime->push(['type' => 'window', 'window' => $window, 'event' => 'close-requested']);
        $this->frames(1);
    }

    /** The user picks $item in the menu of $window ('*': the application's menu); $checked: a check item's new state. */
    protected function menu(string $item, string $window = 'main', ?bool $checked = null): void
    {
        $this->runtime->push(['type' => 'menu', 'window' => $window, 'item' => $item, 'checked' => $checked]);
        $this->frames(1);
    }

    /** The user answers the last dialog PHP opened ($answer: bool, a path, a list of paths, null = cancel). */
    protected function answerDialog(mixed $answer): void
    {
        $dialogs = array_filter($this->runtime->sent, static fn (array $c): bool => str_starts_with((string)($c['cmd'] ?? ''), 'dialog.'));
        $last = end($dialogs);
        if ($last === false) {
            self::fail('no dialog was opened');
        }
        $this->runtime->push(['type' => 'dialog', 'id' => $last['id'], 'result' => $answer]);
        $this->frames(1);
    }

    /** @return list<array<string, mixed>> the commands sent to the native side named $cmd ('window.set_title'...) */
    protected function sent(string $cmd): array
    {
        return $this->runtime->sentOf($cmd);
    }

    /** The last command named $cmd, or null. */
    protected function lastSent(string $cmd): ?array
    {
        $all = $this->sent($cmd);
        return $all === [] ? null : $all[count($all) - 1];
    }
}
