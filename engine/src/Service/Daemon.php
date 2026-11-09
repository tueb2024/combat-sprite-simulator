<?php

namespace JumPHP\Service;

/**
 * A program that runs in the background as a service (bin/jumphp service:install, docs/services.md): calls $tick every
 * $every seconds until SIGTERM / SIGINT (systemd, launchd stop) or Ctrl+C / Ctrl+Break / closing (Windows), then $stop
 * once and returns 0. An exception in $tick is logged and returns 1, so the service manager restarts it (restart:
 * on-failure). Logs go to stderr with the time: journald, launchd's log file or WinSW's log keep them.
 *
 *   exit(Daemon::loop(fn () => $this->work(), 1.0, fn () => $this->save()));
 *
 * No dependency on the rest of the engine: a CLI app may copy this file as it is.
 */
final class Daemon
{
    private static bool $stopping = false;

    public static function loop(callable $tick, float $every = 1.0, ?callable $stop = null): int
    {
        self::$stopping = false;
        self::listen();
        $code = 0;
        try {
            while (!self::stopping()) {
                $next = microtime(true) + $every;
                $tick();
                // sleep in short slices: a stop request is seen within 0.1 s
                while (($left = $next - microtime(true)) > 0) {
                    usleep((int)(min($left, 0.1) * 1e6));
                    if (function_exists('pcntl_signal_dispatch')) {
                        pcntl_signal_dispatch();
                    }
                    if (self::stopping()) {
                        break;
                    }
                }
            }
            self::log('stopping');
        } catch (\Throwable $e) {
            self::log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $code = 1;
        }
        if ($stop !== null) {
            $stop();
        }
        return $code;
    }

    /** Ends the loop after the current tick (from $tick itself, or a signal). */
    public static function stop(): void
    {
        self::$stopping = true;
    }

    /** @phpstan-impure a signal handler changes it */
    public static function stopping(): bool
    {
        return self::$stopping;
    }

    public static function log(string $message): void
    {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n");
    }

    private static function listen(): void
    {
        if (function_exists('pcntl_signal')) {
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(true);
            }
            foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
                pcntl_signal($signal, static function (int $signal): void {
                    self::log('signal ' . $signal);
                    self::stop();
                });
            }
        } elseif (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static function (int $event): void {
                self::log('console event ' . $event);
                self::stop();
            });
        }
    }
}
