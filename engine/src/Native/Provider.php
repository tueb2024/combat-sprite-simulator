<?php

namespace JumPHP\Native;

use JumPHP\Async\Task;

/**
 * Who does the native features on a target: TauriProvider (desktop apps), SdlProvider (games on the desktop),
 * BrowserProvider (the browser build), NullProvider (none). Native::provider() picks it.
 * Features: message, confirm, open, save, folder, notify, openUrl, openPath, reveal, copy, paste.
 */
interface Provider
{
    public function name(): string;

    public function supports(string $feature): bool;

    /** @return Task true when closed */
    public function message(string $message, string $title, string $kind): Task;

    /** @return Task bool */
    public function confirm(string $message, string $title, ?string $ok, ?string $cancel): Task;

    /** @return Task string|list<string>|null (null: cancelled) */
    public function open(array $options): Task;

    /** @return Task string|null */
    public function save(array $options): Task;

    public function notify(string $title, string $body): bool;

    public function openUrl(string $url): bool;

    public function openPath(string $path): bool;

    public function reveal(string $path): bool;

    /** Text to the clipboard. */
    public function copy(string $text): bool;

    /** @return Task string: the clipboard's text */
    public function paste(): Task;
}
