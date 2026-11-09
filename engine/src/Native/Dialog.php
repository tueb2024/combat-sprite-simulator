<?php

namespace JumPHP\Native;

use JumPHP\Async\Task;

/**
 * The system's message boxes.
 *
 *   Dialog::message('Saved.', 'Notes');                               // Task: true once closed
 *   Dialog::message('Disk full', 'Notes', 'error');                   // kind: info, warning, error
 *   Dialog::confirm('Delete this note?', 'Notes', 'Delete', 'Keep')
 *       ->then(fn (bool $yes) => $yes && $this->delete());
 */
final class Dialog
{
    public static function message(string $message, string $title = '', string $kind = 'info'): Task
    {
        return Native::provider()->message($message, $title, $kind);
    }

    /** @return Task bool (both labels or none: OK / Cancel in the system's language) */
    public static function confirm(string $message, string $title = '', ?string $ok = null, ?string $cancel = null): Task
    {
        return Native::provider()->confirm($message, $title, $ok, $cancel);
    }
}
