<?php

namespace JumPHP\Tauri;

use JumPHP\Async\Task;

/** A newer version of the app, found by Updater::check() (its signature checked). */
final class Update
{
    public function __construct(
        private readonly Updater $updater,
        public readonly string $version,
        public readonly string $notes,
        public readonly string $url,
        public readonly string $sha256,
        public readonly int $size,
    ) {
    }

    /** Downloads it, checks its SHA-256 and replaces the app's executable: settles with true (then relaunch()). */
    public function install(): Task
    {
        return $this->updater->install($this);
    }
}
