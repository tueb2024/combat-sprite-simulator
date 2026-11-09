<?php

namespace JumPHP\Kernel;

use JumPHP\Audio\SoundManager;
use JumPHP\Bridge\JsBridge;
use JumPHP\Debug\FpsCounter;
use JumPHP\Debug\LiveFrame;
use JumPHP\Entity\EntityManager;
use JumPHP\Input\ActionMap;
use JumPHP\Input\Gamepad;
use JumPHP\Input\InputManager;
use JumPHP\Input\Keyboard;
use JumPHP\Input\Mouse;
use JumPHP\Input\Touch;
use JumPHP\Loop\GameLoop;
use JumPHP\Physics\CollisionSystem;
use JumPHP\Renderer\BitmapFont;
use JumPHP\Renderer\Sdl;
use JumPHP\Save\Options;
use JumPHP\Save\Storage;
use JumPHP\Scene\Scene;
use JumPHP\Scene\SceneManager;
use JumPHP\Tile\TileMapRenderer;
use JumPHP\Util\Lang;
use React\EventLoop\Loop;
use Symfony\Component\Config\FileLocator;
use JumPHP\Extension\Extensions;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Boots a game. The game's app.php (desktop) and the page (browser) both go through here:
 *
 *   exit(JumPHP\Kernel\Application::boot(__DIR__)->run());   // app.php
 *
 * Desktop: run() blocks until the window closes. Browser: boot() returns, then the page calls
 * jumphp_frame() -> frame() at each requestAnimationFrame.
 *
 * Headless modes (no window, fixed 1/60 s frames), driven by environment variables:
 *   JUMPHP_SMOKE=1        run JUMPHP_MAX_FRAMES frames (default 600), any PHP warning is fatal (exit 1)
 *   JUMPHP_SCREENSHOT=f   run the frames then save the last one as a PNG (JUMPHP_SHOT_EVERY=n: one every n frames)
 *   JUMPHP_LIVE=f         the current frame written to the PNG f every JUMPHP_LIVE_EVERY frames (default 10), replaced
 *                         at once (a viewer reads whole pictures only): what the Claude Code pane jumphp-live shows
 *   JUMPHP_INPUT=json     scripted input: [[frame, "action"], [frame, "action", "hold"|"release"], ...]
 *   JUMPHP_INPUT_RECORD=file  writes the actions held / let go as the game is played, in that same format (InputRecorder)
 *   JUMPHP_SCENE=Class    first scene (JUMPHP_SCENE_PARAMS={"json":1})
 *   JUMPHP_REALTIME=1     headless frames paced in real time (network tests between two processes)
 */
final class Application
{
    public const CACHED_CONTAINER_CLASS = 'JumPHPCachedContainer';

    public Engine $engine;
    private ?\Throwable $crash = null;
    private int $exitCode = 0;
    private array $script = [];
    private ?\JumPHP\Input\InputRecorder $recorder = null;
    private bool $recorderBound = false;

    private function __construct(public readonly ContainerInterface $container, public readonly GameConfig $config)
    {
    }

    public static function boot(string $root): self
    {
        $root = rtrim($root, '/');
        if (!defined('JUMPHP_ROOT')) {
            define('JUMPHP_ROOT', $root);
        }
        if (Platform::setting('JUMPHP_SMOKE') !== null) {
            set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
                if (!(error_reporting() & $level)) {
                    return false;
                }
                throw new \ErrorException($message, 0, $level, $file, $line);
            });
        }
        if (Platform::isHeadless()) {
            if (!Platform::setting('JUMPHP_HEADLESS_GL')) {
                putenv('SDL_VIDEODRIVER=dummy');     // JUMPHP_HEADLESS_GL: a real window on Xvfb, with OpenGL
            }
            putenv('SDL_AUDIODRIVER=dummy');
            putenv('ALSOFT_DRIVERS=null');           // OpenAL (Audio3d): silent output, still a working device
        }

        $config = GameConfig::load($root);
        $container = self::loadContainer($root, $config);
        $app = new self($container, $config);
        $app->engine = $container->get(Engine::class);
        $extensions = $container->get(Extensions::class);
        $app->engine->setExtensions($extensions);
        $extensions->attach($app->engine);
        $app->engine->scenes->setResolver(static function (string $class) use ($container): Scene {
            if ($container->has($class)) {
                return $container->get($class);
            }
            if (!class_exists($class)) {
                throw new \RuntimeException("Scene class '$class' not found (namespace / file name?)");
            }
            return new $class();
        });
        if (Platform::isHeadless()) {
            $app->engine->fixedDelta = 1 / 60;
            $app->script = json_decode((string)Platform::setting('JUMPHP_INPUT', '[]'), true) ?: [];
            $record = (string)Platform::setting('JUMPHP_INPUT_RECORD', '');
            $app->recorder = $record !== '' ? new \JumPHP\Input\InputRecorder($record) : null;
        }
        $app->engine->boot();

        if (Platform::isDesktop()) {
            // headless frames run as fast as possible, unless JUMPHP_REALTIME=1 (network tests: real seconds)
            $interval = Platform::isHeadless() && !Platform::setting('JUMPHP_REALTIME') ? 0.0 : 1 / max(1, (int)$config->get('max_fps'));
            $app->engine->gameLoop->addPeriodicTimer($interval, fn () => $app->tickDesktop());
        }
        return $app;
    }

    /** Browser: one frame (called by jumphp_frame()). */
    public function frame(): void
    {
        if ($this->crash !== null) {
            $this->renderCrash();
            return;
        }
        try {
            $this->engine->frame();
        } catch (\Throwable $e) {
            $this->crashed($e);
        }
    }

    /**
     * Browser, between two frames: the page's events now (called by jumphp_events(): the tab is being hidden
     * or closed and may not run another frame; a 'hide' / 'close' listener can still save).
     */
    public function events(): void
    {
        if ($this->crash !== null || !$this->engine->isRunning()) {
            return;
        }
        try {
            $this->engine->bridge->poll();
        } catch (\Throwable $e) {
            $this->crashed($e);
        }
    }

    /** Desktop: runs until the game quits. @return int exit code */
    public function run(): int
    {
        // ui: hybrid (a game that also drives Tauri: native dialogs, HTML windows): its runtime runs the loop
        $hybrid = \JumPHP\Tauri\Hybrid::wanted($this->config) ? \JumPHP\Tauri\Hybrid::run($this) : null;
        if ($hybrid === null) {
            $this->engine->gameLoop->start();
        } elseif ($hybrid !== 0 && $this->exitCode === 0) {
            $this->exitCode = $hybrid;
        }
        if ($this->crash === null) {
            $this->engine->closeAudio3d();
            $this->engine->sound->close();
            $this->engine->sdl->quit();
        }
        return $this->exitCode;
    }

    /** 0 until the game crashed (1). */
    public function exitCode(): int
    {
        return $this->exitCode;
    }

    private function tickDesktop(): void
    {
        try {
            $frame = $this->engine->frameCount();
            foreach ($this->script as $step) {
                if ((int)$step[0] === $frame) {
                    $mode = $step[2] ?? 'press';
                    $mode === 'press' ? $this->engine->actions->press((string)$step[1])
                        : $this->engine->actions->hold((string)$step[1], $mode === 'hold');
                }
            }
            if ($this->recorder !== null && !$this->recorderBound) {
                // in the frame's own event: before its input is cleared, so a tap shorter than a frame is seen too
                $this->recorderBound = true;
                $recorder = $this->recorder;
                $this->engine->on('frame', fn () => $recorder->observe($this->engine->frameCount(), $this->engine->actions));
            }
            $this->engine->tick();
            if (!$this->engine->isRunning()) {
                $this->engine->gameLoop->stop();
                return;
            }
            if (Platform::isHeadless()) {
                $this->headlessFrameDone();
            } elseif (($max = (int)Platform::setting('JUMPHP_MAX_FRAMES', 0)) > 0 && $this->engine->frameCount() >= $max) {
                self::stderr("[jumphp] stopped after $max frames (JUMPHP_MAX_FRAMES)\n");
                $this->engine->quit();
            }
        } catch (\Throwable $e) {
            $this->crashed($e);
            $this->engine->gameLoop->stop();
        }
    }

    private function headlessFrameDone(): void
    {
        $frame = $this->engine->frameCount();
        $shot = Platform::setting('JUMPHP_SCREENSHOT');
        $every = (int)Platform::setting('JUMPHP_SHOT_EVERY', 0);
        $max = (int)Platform::setting('JUMPHP_MAX_FRAMES', $shot !== null ? 120 : 600);
        $live = Platform::setting('JUMPHP_LIVE');
        if ($live !== null && $frame % max(1, (int)Platform::setting('JUMPHP_LIVE_EVERY', 10)) === 0) {
            LiveFrame::write($this->engine->sdl, (string)$live);
        }
        if ($shot !== null && $every > 0 && $frame % $every === 0) {
            $this->engine->sdl->saveScreenshot(preg_replace('/\.png$/', '', (string)$shot) . sprintf('-%05d.png', $frame));
        }
        if ($frame >= $max) {
            if ($shot !== null) {
                $this->engine->sdl->saveScreenshot((string)$shot);
                self::stderr("[jumphp] screenshot: $shot (frame $frame)\n");
            } else {
                self::stderr("[jumphp] smoke test OK: $frame frames, scene " . get_class($this->engine->scenes->current() ?? $this) . "\n");
            }
            $this->engine->gameLoop->stop();
        }
    }

    /** STDERR is not defined in the browser build: php://stderr goes to the console there. */
    public static function stderr(string $message): void
    {
        $stream = defined('STDERR') ? STDERR : @fopen('php://stderr', 'w');
        if ($stream) {
            fwrite($stream, $message);
        }
    }

    private function crashed(\Throwable $e): void
    {
        $this->crash = $e;
        $this->exitCode = 1;
        self::stderr("[jumphp] CRASH: " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()
            . "\n" . $e->getTraceAsString() . "\n");
        if (Platform::isWasm()) {
            $this->renderCrash();
        }
    }

    /** Browser: the error on screen instead of a frozen picture. */
    private function renderCrash(): void
    {
        try {
            $r = $this->engine->sdl->getRenderer();
            $font = new BitmapFont($this->engine->sdl);
            $r->beginFrame([90, 0, 0]);
            $msg = wordwrap(get_class($this->crash) . ': ' . $this->crash->getMessage(), (int)max(10, $r->getWidth() / 6 - 2), "\n", true);
            $where = basename($this->crash->getFile()) . ':' . $this->crash->getLine();
            $font->draw($r, "CRASH\n\n" . $msg . "\n\n" . $where . "\n\nSEE THE BROWSER CONSOLE", 4, 4, 1, [255, 255, 255]);
            $r->endFrame();
        } catch (\Throwable) {
        }
    }

    // --- service container -------------------------------------------------------------------------

    private static function loadContainer(string $root, GameConfig $config): ContainerInterface
    {
        $cached = $root . '/cache/container.php';
        if (Platform::setting('JUMPHP_CONTAINER_CACHE') && is_file($cached)) {
            require_once $cached;
            $class = self::CACHED_CONTAINER_CLASS;
            $container = new $class();
        } else {
            $container = self::buildContainer($root);
            $container->compile();
        }
        $container->set(GameConfig::class, $config);
        return $container;
    }

    /** The engine services + the game's config/services.yaml (not compiled: tools/dump-container.php dumps it). */
    public static function buildContainer(string $root): ContainerBuilder
    {
        $c = new ContainerBuilder();
        // scenes are fetched by class name: public, one instance each (reset in enter())
        $c->registerForAutoconfiguration(Scene::class)->setPublic(true)->setShared(true);

        $c->register(GameConfig::class)->setSynthetic(true)->setPublic(true);
        foreach ([GameLoop::class, Sdl::class, Keyboard::class, Mouse::class, Touch::class, Gamepad::class, ActionMap::class,
                     InputManager::class, SceneManager::class, SoundManager::class, Options::class, JsBridge::class,
                     FpsCounter::class, TileMapRenderer::class, BitmapFont::class] as $class) {
            $c->autowire($class)->setPublic(true);
        }
        $c->register(Storage::class)->setFactory([Storage::class, 'fromConfig'])->addArgument(new Reference(GameConfig::class))->setPublic(true);
        $c->register(Lang::class)->setFactory([Lang::class, 'fromConfig'])->addArgument(new Reference(GameConfig::class))->setPublic(true);
        // one per scene that asks for it
        $c->autowire(CollisionSystem::class)->setShared(false)->setPublic(true);
        $c->autowire(EntityManager::class)->setShared(false)->setPublic(true);
        $c->autowire(Engine::class)->setPublic(true);
        // the install guide of the JumPHP runtime (a scene, `scenes->push(RuntimeGuide::class)`; the browser's applications show it from RuntimeBadge)
        $c->autowire(\JumPHP\Runtime\RuntimeGuide::class)->setPublic(true)->setShared(true);

        // the engine extensions the game requires (Composer type jumphp-extension), before its own services
        Extensions::register($c, $root);
        if (is_file($root . '/config/services.yaml')) {
            (new YamlFileLoader($c, new FileLocator($root . '/config')))->load('services.yaml');
        }
        return $c;
    }
}
