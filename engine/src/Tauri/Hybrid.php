<?php

namespace JumPHP\Tauri;

use JumPHP\Kernel\Application;
use JumPHP\Kernel\GameConfig;
use JumPHP\Kernel\Platform;

/**
 * A game (SDL) that also drives Tauri (game.yaml `ui: hybrid`): the native features of desktop apps for a game
 * (dialogs, file choosers, notifications, tray icon, global shortcuts, clipboard: JumPHP\Native) and, if wanted,
 * HTML windows beside the SDL one (a control panel, an editor, a launcher...).
 *
 * Application::run() hands over to Hybrid::run(): the Tauri runtime runs the main thread's loop and calls the
 * app's frame, which runs one turn of the ReactPHP loop, where the game's own frame timer ticks the engine
 * (Application::boot() set it). Nothing else changes for the game: scenes, SDL window, sound.
 *
 * Without what it needs (headless runs, the browser, no FFI, no runtime library) the game runs as a plain game.
 *
 * game.yaml:
 *   ui: hybrid
 *   main: App\Desktop            # optional: an AppMain, its boot(App $app) registers commands, a tray icon...
 *   app:
 *     window: {url: panel.html, width: 360, height: 480}    # optional: an HTML window (pages: ui/)
 */
final class Hybrid
{
    public static function wanted(GameConfig $config): bool
    {
        return $config->get('ui') === 'hybrid' && !Platform::isWasm() && !Platform::isHeadless()
            && !Platform::isPhoneApp();
    }

    /**
     * The game in the Tauri window (app.embed: true; JumPHP\Tauri\EmbeddedGame): only when it can (what wanted()
     * asks, FFI, the runtime library), else it keeps its SDL window.
     */
    public static function embedded(GameConfig $config): bool
    {
        return self::wanted($config) && ($config->get('app', [])['embed'] ?? false) === true
            && TauriRuntime::canLoad($config->root());
    }

    /** @return int|null the exit code, or null when the game has to run as a plain game */
    public static function run(Application $game, ?NativeRuntime $runtime = null): ?int
    {
        $config = $game->config;
        $runtime ??= TauriRuntime::create($config->root());
        if ($runtime === null) {
            Application::stderr("[jumphp] ui: hybrid, but no Tauri runtime here (FFI + bin/jumphp tauri:build): running as a plain game\n");
            return null;
        }
        $app = new App($config, $runtime);
        $window = $config->get('app', [])['window'] ?? null;
        if ($game->engine->sdl->isEmbedded()) {
            (new EmbeddedGame($app, $game->engine, $config))->start();
        } elseif (is_array($window)) {
            $app->createWindow('main', $window + ['title' => $config->title()]);
        }
        $main = $config->get('main');
        if ($main !== null) {
            $object = class_exists((string)$main) ? new $main() : null;
            if (!$object instanceof AppMain) {
                throw new \RuntimeException("game.main: $main must be a class implementing " . AppMain::class);
            }
            $object->boot($app);
        }

        $engine = $game->engine;
        $engine->on('quit', fn () => $app->quit($game->exitCode()));
        $app->on('frame', function () use ($app, $engine, $game): void {
            if (!$engine->isRunning() || $game->exitCode() !== 0) {
                $app->quit($game->exitCode());
            }
        });
        // with an HTML window: closing the last one does not end the game (the SDL window does)
        $app->on('exit-requested', fn (NativeEvent $e) => $engine->isRunning() ? $e->preventDefault() : null);
        // frames as often as the game wants them (its timer decides when it ticks)
        $app->setTickRate((float)max(30, (int)$config->get('max_fps', 120)));
        return $app->run();
    }
}
