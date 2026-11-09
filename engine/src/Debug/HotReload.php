<?php

namespace JumPHP\Debug;

use JumPHP\Kernel\Application;
use JumPHP\Kernel\Engine;
use JumPHP\Scene\HotReloadable;
use JumPHP\Scene\Scene;

/**
 * Hot reload, in the browser (the playground's sandbox and online editor): the page writes the files that changed
 * into /game, then calls apply() between two frames. By what changed:
 *   pictures, sounds        reloaded in place (Sdl, SoundManager): the game goes on, nothing restarts
 *   config/lang.yaml, config/input.yaml   reloaded in place
 *   PHP code, config/game.yaml, config/services.yaml   "restart": the page saves the playing scene (save()),
 *                           restarts PHP in the same engine (phpw_restart: no page reload), and the engine
 *                           starts again in that scene (pending()), with its state if it is HotReloadable
 *   anything else (maps, data)   the playing scene is entered again (its state kept if HotReloadable)
 * The answer ('assets', 'scene', 'restart') is written to RESULT for the page.
 */
final class HotReload
{
    public const STATE = '/tmp/jumphp-hot.json';
    public const RESULT = '/tmp/jumphp-hot-result';
    private const IMAGE = '/\.(png|jpe?g|gif|bmp|webp)$/i';
    private const SOUND = '/\.(wav|ogg|mp3|flac|mod|xm|it|s3m)$/i';
    private const RESTART = ['config/project.yaml', 'config/game.yaml', 'config/services.yaml', 'composer.json'];
    /** what the game does not read while it plays: its docs, tests, web site */
    private const IGNORED = '#(^|/)[^/]*\.(md|txt)$|^(tests|site|docs)/|(^|/)\.git#i';

    /** The page: these files (relative to the game) changed. */
    public static function apply(string $pathsJson): void
    {
        $app = $GLOBALS['app'] ?? null;
        $paths = json_decode($pathsJson, true);
        $result = $app instanceof Application && is_array($paths) ? self::reload($app->engine, array_map('strval', $paths)) : 'restart';
        file_put_contents(self::RESULT, $result);
    }

    /** @param string[] $paths relative to the game */
    public static function reload(Engine $engine, array $paths): string
    {
        $files = [];
        $isSceneChanged = false;
        foreach ($paths as $path) {
            if (preg_match(self::IGNORED, $path)) {
                continue;
            }
            if (str_ends_with($path, '.php') || in_array($path, self::RESTART, true)) {
                return 'restart';
            }
            if ($path === 'config/lang.yaml') {
                $engine->lang->setTables($engine->config->file('lang.yaml'));
            } elseif ($path === 'config/input.yaml') {
                $engine->actions->load(Engine::DEFAULT_ACTIONS);
                $engine->actions->load($engine->config->file('input.yaml')['actions'] ?? []);
            } elseif (preg_match(self::IMAGE, $path) || preg_match(self::SOUND, $path)) {
                $files[] = $engine->config->path($path);
            } else {
                $isSceneChanged = true;
            }
        }
        $engine->sdl->reloadFiles($files);
        $engine->sound->reloadFiles($files);
        if ($isSceneChanged && ($scene = self::playing($engine)) !== null) {
            $state = $scene instanceof HotReloadable ? $scene->hotState() : null;
            $engine->scenes->switchTo(get_class($scene), $engine->scenes->paramsOf($scene), false);
            self::restore($engine, $state);
            return 'scene';
        }
        return 'assets';
    }

    /** The page, before a restart: the playing scene, its parameters and state, for the next boot (pending()). */
    public static function save(string $file = self::STATE): void
    {
        $app = $GLOBALS['app'] ?? null;
        $scene = $app instanceof Application ? self::playing($app->engine) : null;
        if ($scene === null) {
            @unlink($file);
            return;
        }
        $state = null;
        try {
            $state = $scene instanceof HotReloadable ? $scene->hotState() : null;
        } catch (\Throwable $e) {
            Application::stderr('[jumphp] hotState(): ' . $e->getMessage() . "\n");
        }
        $params = $app->engine->scenes->paramsOf($scene);
        $json = json_encode(['scene' => get_class($scene), 'params' => $params, 'state' => $state], JSON_PARTIAL_OUTPUT_ON_ERROR);
        file_put_contents($file, $json !== false ? $json : json_encode(['scene' => get_class($scene), 'params' => [], 'state' => null]));
    }

    /** The page, just before the restart: the engine closes what it holds (the new boot opens it again). */
    public static function shutdown(): void
    {
        $app = $GLOBALS['app'] ?? null;
        if ($app instanceof Application) {
            $app->engine->shutdown();
        }
    }

    /**
     * The boot after a restart: the scene to start in (if its class still exists), its parameters and state.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: ?array<string, mixed>}|null
     */
    public static function pending(string $file = self::STATE): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $saved = json_decode((string)file_get_contents($file), true);
        @unlink($file);
        if (!is_array($saved) || !is_string($saved['scene'] ?? null) || !class_exists($saved['scene'])) {
            return null;
        }
        return [$saved['scene'], (array)($saved['params'] ?? []), is_array($saved['state'] ?? null) ? $saved['state'] : null];
    }

    /** The state given back to the scene just entered (its own code checks it: a bad one does not stop the game). */
    public static function restore(Engine $engine, ?array $state): void
    {
        $scene = $engine->scenes->current();
        if ($state === null || !$scene instanceof HotReloadable) {
            return;
        }
        try {
            $scene->hotRestore($state);
        } catch (\Throwable $e) {
            Application::stderr('[jumphp] hotRestore(): ' . $e->getMessage() . "\n");
        }
    }

    /** the scene being played: the top one that is not an overlay (a pause menu over it is left) */
    private static function playing(Engine $engine): ?Scene
    {
        $stack = $engine->scenes->stack();
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            if (!$stack[$i]->isOverlay()) {
                return $stack[$i];
            }
        }
        return null;
    }
}
