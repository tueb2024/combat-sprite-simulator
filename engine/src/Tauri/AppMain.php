<?php

namespace JumPHP\Tauri;

/**
 * The main class of a desktop app (game.yaml `main: App\Main`): made with `new`, then boot() once, before the
 * window opens, to register the page's commands and the listeners.
 *
 *   final class Main implements AppMain
 *   {
 *       public function boot(App $app): void
 *       {
 *           $app->command('greet', fn (array $p) => 'Hello ' . $p['name']);
 *           $app->on('ready', fn () => $app->window()->setTitle('Ready'));
 *       }
 *   }
 */
interface AppMain
{
    public function boot(App $app): void;
}
