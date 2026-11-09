<?php

namespace JumPHP\Tauri;

/**
 * An event of the native side that has a default action (a window asked to close: it closes; the last window
 * is gone: the app quits). A listener calls preventDefault() to stop it.
 *
 *   $app->window()->on('close-requested', function (NativeEvent $e) use ($app) {
 *       $e->preventDefault();                                     // keep the window...
 *       Dialog::confirm('Quit?')->then(fn (bool $yes) => $yes && $app->quit());
 *   });
 */
final class NativeEvent
{
    private bool $prevented = false;

    /** @param array<string, mixed> $data */
    public function __construct(public readonly string $name, public readonly array $data = [])
    {
    }

    public function preventDefault(): void
    {
        $this->prevented = true;
    }

    public function isDefaultPrevented(): bool
    {
        return $this->prevented;
    }
}
