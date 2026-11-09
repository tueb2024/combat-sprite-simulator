<?php

namespace JumPHP\Tauri;

use JumPHP\Bridge\JsBridge;

/**
 * A desktop app in a web page (bin/jumphp build <app>: the browser, phones through bin/jumphp twa or as a web app):
 * the same App, pages and PHP, with PHP in JumPHP's WebAssembly engine instead of Tauri. The native commands go to
 * the page as JsBridge messages {t: 'tauri', cmd: ...}; engine/web/jumphp-app.js carries them out in HTML (windows
 * as frames, the menu bar, dialogs, the file picker, notifications, the clipboard) and answers with the same events
 * Tauri sends ({t: 'tauri', type: 'invoke' | 'dialog' | 'menu' | ...}).
 *
 * Not blocking: run() hands the config to the page and returns; the page calls App::frame() at each frame
 * (engine/bootstrap/wasm.php, jumphp_frame()).
 */
final class BrowserRuntime implements NativeRuntime
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    public function __construct(public readonly JsBridge $bridge = new JsBridge())
    {
        $bridge->on('tauri', function (array $event): void {
            $this->events[] = $event;
        });
    }

    public function run(array $config, \Closure $frame): int
    {
        $this->bridge->send('tauri', ['cmd' => 'app.start'] + $config);
        return 0;
    }

    public function poll(): array
    {
        $this->bridge->poll();
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    public function send(array $command): void
    {
        $this->bridge->send('tauri', $command);
    }

    public function picture(string $bmp): void
    {
        // an embedded game has its own browser build (bin/jumphp build of the game itself)
    }

    public function version(): string
    {
        return 'browser';
    }
}
