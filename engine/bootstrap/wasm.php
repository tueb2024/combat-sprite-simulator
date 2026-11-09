<?php

// Browser entry point. The page (engine/web/jumphp.js) evaluates its prelude (define('JUMPHP_WASM', true), ...)
// then requires this file. phpw.c (docker/engine-wasm) then calls jumphp_frame() at each requestAnimationFrame.
// The game files are at /game (emscripten file_packager), the player's files at /game/var (IndexedDB).
// jumphp_events() is called by the page between frames (tab hidden, page closing: JsBridge events now).
chdir('/game');
require '/game/vendor/autoload.php';

try {
    if (\JumPHP\Kernel\GameConfig::load('/game')->get('ui') === 'webview') {
        // a desktop app (docs/tauri.md "In the browser"): its pages in the page, its PHP here
        $GLOBALS['app'] = \JumPHP\Tauri\App::boot('/game', new \JumPHP\Tauri\BrowserRuntime());
        $GLOBALS['app']->start();
    } else {
        $GLOBALS['app'] = \JumPHP\Kernel\Application::boot('/game');
    }
} catch (\Throwable $e) {
    \JumPHP\Kernel\Application::stderr('[jumphp] BOOT FAILED: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n"
        . $e->getTraceAsString() . "\n");
    return;
}

// declared only when the boot worked (a conditional declaration runs at execution time, not at compile time):
// without it, phpw_run() returns and the page shows the error
if (isset($GLOBALS['app'])) {
    function jumphp_frame(): void
    {
        $GLOBALS['app']->frame();
    }

    function jumphp_events(): void
    {
        $GLOBALS['app']->events();
    }
}
