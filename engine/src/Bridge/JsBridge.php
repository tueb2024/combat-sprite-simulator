<?php

namespace JumPHP\Bridge;

use Evenement\EventEmitter;
use JumPHP\Kernel\Platform;

/**
 * The link between the game and the web page (browser only), both ways, synchronous with the frames:
 *
 * - game -> page: send() appends one JSON line to /tmp/jumphp-bridge; the page reads it right after the PHP
 *   frame (emscripten postMainLoop, engine/web/jumphp.js) and opens a link, shares, goes back to the portal,
 *   or calls window.JumPHP.onBridge(message) for the types it does not know.
 * - page -> game: the page writes its events (JSON lines) to /tmp/jumphp-events right before the PHP frame
 *   (preMainLoop); poll() reads them at the start of the frame and emits each one: on('<type>', fn (array $data)).
 *   Engine services use it (lifecycle, HTTP, WebGL...), a page can send its own: window.JumPHP.send('hello', {...}).
 *
 * On desktop, send() does nothing and returns false, poll() never emits.
 *
 *   $bridge->send('open', ['url' => 'https://example.org']);
 *   $bridge->send('quit');                      // the page goes back (?back=URL) or shows "thanks for playing"
 *   $bridge->on('hello', fn (array $data) => ...);
 */
class JsBridge extends EventEmitter
{
    public const FILE = '/tmp/jumphp-bridge';
    public const EVENTS = '/tmp/jumphp-events';

    public function send(string $type, array $data = []): bool
    {
        if (!Platform::isWasm()) {
            return false;
        }
        return file_put_contents(self::FILE, json_encode(['t' => $type] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND) !== false;
    }

    /** Emits the page's events (called by the engine at the start of each frame). @return int events emitted */
    public function poll(): int
    {
        if (!Platform::isWasm() || !is_file(self::EVENTS)) {
            return 0;
        }
        $text = (string)file_get_contents(self::EVENTS);
        @unlink(self::EVENTS);
        $count = 0;
        foreach (explode("\n", $text) as $line) {
            $event = $line === '' ? null : json_decode($line, true);
            if (!is_array($event) || !isset($event['t'])) {
                continue;
            }
            $type = (string)$event['t'];
            unset($event['t']);
            $this->emit($type, [$event]);
            $count++;
        }
        return $count;
    }
}
