<?php

namespace JumPHP\Net;

use Evenement\EventEmitterInterface;
use JumPHP\Async\Task;

/**
 * The engine's view of the JumPHP runtime (the small program that lets an application running in the browser connect from the
 * user's own computer and network: docs/runtime.md, docs/runtime-protocol.md). The core only knows this interface; the engine
 * extension `jumphp/runtime` replaces the default (NoRuntime: always `unsupported`, so the server's bridge is used) by a real client
 * through `Engine::setRuntime()`. Event `change` (string $state) when the state changes (`on('change', fn (string $state) => ...)`). The core never depends on the extension.
 */
interface RuntimeLink extends EventEmitterInterface
{
    public const UNKNOWN = 'unknown';
    public const CHECKING = 'checking';
    /** nothing listens: not installed, or not started */
    public const MISSING = 'missing';
    /** it answers, this application is not authorised yet */
    public const RUNNING = 'running';
    /** it answers and this application is authorised */
    public const READY = 'ready';
    public const REFUSED = 'refused';
    /** not in a browser, or a browser that blocks it */
    public const UNSUPPORTED = 'unsupported';

    /** One of the constants above. */
    public function state(): string;

    /** The WebSocket address of the runtime (ws://127.0.0.1:47820/v1), without a query. */
    public function bridgeUrl(): string;

    /** Looks for the runtime (Task: the state once known). */
    public function check(): Task;

    /** Asks the user to authorise this application (Task: bool granted). */
    public function pair(string $app, string $title): Task;
}
