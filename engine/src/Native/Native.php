<?php

namespace JumPHP\Native;

use JumPHP\Kernel\Engine;
use JumPHP\Kernel\Platform;
use JumPHP\Tauri\App;

/**
 * The system's own features from PHP, the same calls on every target (Dialog, FilePicker, Notification, Shell).
 * Who does them (provider()):
 *
 * | target                               | provider | features                                       |
 * |--------------------------------------|----------|------------------------------------------------|
 * | desktop app (JumPHP\Tauri\App)       | tauri    | all: message, confirm, open, save, folder, notify, openUrl, openPath, reveal, copy, paste |
 * | game on the desktop (SDL)            | sdl      | message                                        |
 * | browser build                        | browser  | openUrl, copy, open (a file; the page writes it into /tmp/jumphp-picked) |
 * | headless runs (test, shot), others   | none     | nothing                                        |
 *
 * What a target lacks fails its Task with UnsupportedFeature (or returns false): check supports() first.
 * Answers come back as Tasks (never blocks the frame): Dialog::confirm('Quit?')->then(fn (bool $yes) => ...).
 */
final class Native
{
    private static ?Provider $provider = null;

    public static function provider(): Provider
    {
        if (self::$provider !== null) {
            return self::$provider;
        }
        $app = App::current();
        if ($app !== null) {
            return new TauriProvider($app);
        }
        if (Platform::isHeadless()) {
            return new NullProvider();
        }
        try {
            $engine = Engine::get();
        } catch (\LogicException) {
            return new NullProvider();
        }
        if (Platform::isWasm()) {
            return new BrowserProvider($engine->bridge);
        }
        return SdlProvider::isAvailable() ? new SdlProvider() : new NullProvider();
    }

    /** Another provider (tests, a custom one); null: back to the automatic choice. */
    public static function setProvider(?Provider $provider): void
    {
        self::$provider = $provider;
    }

    public static function supports(string $feature): bool
    {
        return self::provider()->supports($feature);
    }

    /** 'tauri', 'sdl', 'browser' or 'none'. */
    public static function name(): string
    {
        return self::provider()->name();
    }
}
