<?php

namespace JumPHP\Testing;

use JumPHP\Debug\LiveFrame;
use JumPHP\Kernel\Application;
use JumPHP\Kernel\Engine;
use JumPHP\Scene\Scene;

/**
 * A game driven by a test, frame by frame, without a window (GameTestCase): start a scene, press or hold actions,
 * run frames, look into the scene (peek its private fields), take a picture.
 *
 *   $game->start(PlayScene::class, ['mode' => 'local']);
 *   $paddle = $game->peek('left');
 *   $game->hold('p1_down', 60);                     // one second with the key held
 *   self::assertGreaterThan($top, $paddle->centerY());
 *
 * Watched: with JUMPHP_LIVE (bin/jumphp test, the Claude Code pane jumphp-live) every few frames go to the live
 * picture, and each test's last frame to var/live/tests/; with JUMPHP_REALTIME the frames run at the game's
 * speed, so that one sees the paddle move. With JUMPHP_FILM=dir each test is filmed (the playground replays it):
 * dir/<n>/name.txt (the test), dir/<n>/<frame>.png every JUMPHP_FILM_EVERY frames (default 4), FILM_MAX at most.
 * The game boots once per PHP process; each test starts its scene.
 */
final class Game
{
    public const FILM_MAX = 150;

    private static ?self $instance = null;

    private string $test = '';
    private int $shots = 0;
    private int $filmed = 0;

    private function __construct(public readonly Application $app, public readonly Engine $engine, private string $root)
    {
    }

    /** The game of the folder $root (the game's folder), booted without a window the first time. */
    public static function boot(string $root): self
    {
        if (self::$instance === null) {
            putenv('JUMPHP_TEST=1');
            $app = Application::boot($root);
            // the test runs the frames: the event loop must not start by itself when PHP ends (ReactPHP runs a
            // loop nobody stopped at shutdown: here, 600 more frames after the tests)
            \React\EventLoop\Loop::stop();
            // the sound and SDL closed before PHP unloads its extensions: else SDL's audio thread may still run
            // the code of the unloaded SDL_mixer (a crash when the tests end, 1 run in 5)
            register_shutdown_function(static function () use ($app): void {
                $app->engine->closeAudio3d();
                $app->engine->sound->close();
                $app->engine->sdl->quit();
            });
            self::$instance = new self($app, $app->engine, rtrim($root, '/'));
        }
        return self::$instance;
    }

    /** Switches to $scene now (no fade) and runs its first frame. */
    public function start(string $scene, array $params = []): Scene
    {
        $this->engine->scenes->switchTo($scene, $params, false);
        $this->run(1);
        return $this->scene();
    }

    public function scene(): Scene
    {
        return $this->engine->scenes->current() ?? throw new \LogicException('No scene: start() one');
    }

    /** Runs $frames frames (1/60 s each). */
    public function run(int $frames = 1): self
    {
        $live = self::live();
        $every = max(1, (int)(getenv('JUMPHP_LIVE_EVERY') ?: 2));
        $realtime = (bool)getenv('JUMPHP_REALTIME');
        for ($i = 0; $i < $frames; $i++) {
            $started = microtime(true);
            $this->engine->tick();
            if ($live !== null && $this->engine->frameCount() % $every === 0) {
                $this->save($live);
            }
            $this->film();
            if ($realtime) {
                usleep(max(0, (int)((1 / 60 - (microtime(true) - $started)) * 1e6)));
            }
        }
        return $this;
    }

    /** Runs $seconds of the game. */
    public function wait(float $seconds): self
    {
        return $this->run((int)round($seconds * 60));
    }

    /** One press of an action (a key, a button), then $frames frames. */
    public function press(string $action, int $frames = 1): self
    {
        $this->engine->actions->press($action);
        return $this->run($frames);
    }

    /** An action held for $frames frames, then let go. */
    public function hold(string $action, int $frames): self
    {
        $this->engine->actions->hold($action, true);
        $this->run($frames);
        $this->engine->actions->hold($action, false);
        return $this->run(1);
    }

    /** A field of the scene (or of $object), private or not: what a test checks. */
    public function peek(string $field, ?object $object = null): mixed
    {
        $object ??= $this->scene();
        for ($class = new \ReflectionClass($object); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($field)) {
                return $class->getProperty($field)->getValue($object);
            }
        }
        throw new \LogicException(get_class($object) . " has no field \$$field");
    }

    /** The current frame as a PNG, $name.png beside the live picture (or in var/screenshots/): its path. */
    public function shot(string $name): string
    {
        $live = self::live();
        $dir = $live !== null ? dirname($live) . '/tests' : $this->root . '/var/screenshots/tests';
        $file = $dir . '/' . preg_replace('/[^\w.-]+/', '-', $name) . '.png';
        $this->save($file);
        return $file;
    }

    /** @internal GameTestCase: a test begins (said on STDERR for the pane) */
    public function begin(string $test): void
    {
        $this->test = $test;
        $this->shots++;
        $this->filmed = 0;
        if (($dir = $this->filmDir()) !== null) {
            @mkdir($dir, 0777, true);
            @file_put_contents("$dir/name.txt", $test);
        }
        Application::stderr("[jumphp-test] start $test\n");
    }

    /** @internal GameTestCase: a test ended: its last frame kept, said with its path relative to the live picture */
    public function end(): void
    {
        if ($this->test === '') {
            return;
        }
        $this->film(true);
        $file = $this->shot(str_replace('\\', '.', $this->test));
        $live = self::live();
        $shown = $live !== null ? substr($file, strlen(dirname($live)) + 1) : $file;
        Application::stderr("[jumphp-test] frame {$this->test} $shown\n");
        $this->test = '';
    }

    /** the test's film: a frame every JUMPHP_FILM_EVERY frames, and its last one */
    private function film(bool $isLast = false): void
    {
        $dir = $this->filmDir();
        $every = max(1, (int)(getenv('JUMPHP_FILM_EVERY') ?: 4));
        if ($dir === null || $this->filmed >= self::FILM_MAX || (!$isLast && $this->engine->frameCount() % $every !== 0)) {
            return;
        }
        $this->engine->sdl->saveScreenshot(sprintf('%s/%04d.png', $dir, ++$this->filmed));
    }

    private function filmDir(): ?string
    {
        $film = getenv('JUMPHP_FILM');
        return $film === false || $film === '' || $this->test === '' ? null : sprintf('%s/%03d', rtrim($film, '/'), $this->shots);
    }

    private static function live(): ?string
    {
        $live = getenv('JUMPHP_LIVE');
        return $live === false || $live === '' ? null : $live;
    }

    /** the PNG and its small raw copy for any terminal (LiveFrame) */
    private function save(string $file): void
    {
        LiveFrame::write($this->engine->sdl, $file);
    }
}
