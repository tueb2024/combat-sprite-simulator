<?php

namespace JumPHP\Native;

use JumPHP\Async\Task;

/**
 * The system's file choosers. Options: title, directory (where it starts), file_name (save), filters
 * (['Images' => ['png', 'jpg']]), multiple (open: a list), folder (open: a folder instead of a file).
 *
 *   FilePicker::open(['filters' => ['Text' => ['txt', 'md']]])
 *       ->then(fn (?string $file) => $file !== null && $this->load($file));     // null: cancelled
 *   FilePicker::open(['multiple' => true])->then(fn (?array $files) => ...);
 *   FilePicker::folder()->then(fn (?string $dir) => ...);
 *   FilePicker::save(['file_name' => 'notes.txt'])->then(fn (?string $file) => ...);
 */
final class FilePicker
{
    /** @return Task string|list<string>|null */
    public static function open(array $options = []): Task
    {
        return Native::provider()->open($options);
    }

    /** @return Task string|null */
    public static function folder(array $options = []): Task
    {
        return Native::provider()->open(['folder' => true] + $options);
    }

    /** @return Task string|null */
    public static function save(array $options = []): Task
    {
        return Native::provider()->save($options);
    }
}
