<?php

namespace JumPHP\Tauri;

/**
 * What runs the native window of a desktop app. TauriRuntime is the real one (FFI -> libjumphp_tauri);
 * FakeRuntime plays it in tests (no window, no GTK).
 */
interface NativeRuntime
{
    /** Runs until the app quits; calls $frame at every frame. @return int exit code */
    public function run(array $config, \Closure $frame): int;

    /** The events since the last call (decoded JSON objects). @return list<array<string, mixed>> */
    public function poll(): array;

    /** One command: ['cmd' => 'window.set_title', ...] (run after the current frame). */
    public function send(array $command): void;

    public function version(): string;

    /** The embedded game's newest picture (a BMP; ui: hybrid with app.embed), shown by its page. */
    public function picture(string $bmp): void;
}
