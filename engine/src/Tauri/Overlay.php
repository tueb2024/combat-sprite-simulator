<?php

namespace JumPHP\Tauri;

/**
 * Code updates for an exported desktop app (the JumPHP IDE): the app's PHP and pages, without a new executable.
 *
 * An exported app is an executable (PHP + extensions + the Tauri runtime) followed by a PHAR whose stub unpacks the app once into the
 * cache (`<cache>/<id>-<build>`). An OVERLAY is a signed archive of the files that change from one version to the next (the app's
 * src/, config/, the IDE package's src/ and ui/): it is unpacked in the app's data folder (`<data>/overlay/<version>/tree`) and the
 * stub runs the app from `<root>-ov-<version>`, a copy of the unpacked app (hard links) with the overlay's files on top.
 *
 * Precautions, all here:
 *  - it only holds what the signed update.json announced: the archive's SHA-256, then each file's, are checked before anything is
 *    written; paths are limited to ALLOWED prefixes (never the engine, the executable or vendor/composer);
 *  - `compat`: the overlay says which executable it runs on (config `overlay_compat` of the executable's own tree); another one is ignored;
 *  - rollback: an overlay is `pending` from the moment it starts until the app calls confirm() (the IDE does when its page has loaded). A
 *    start that finds it still pending: if the last one died of a PHP fatal error, the overlay is marked bad, never tried again, and the app
 *    starts from the executable's own files; if it was only not confirmed (killed, frozen), it is tried again, and dropped after MAX_TRIES;
 *  - JUMPHP_NO_OVERLAY=1 starts from the executable's own files (and leaves the overlay alone).
 * The stub requires this file from the unpacked app before anything else is loaded: it depends on nothing.
 */
final class Overlay
{
    /** consecutive starts of one overlay that never confirmed (and did not die of a PHP error) before it is dropped */
    public const MAX_TRIES = 3;

    /** the only places an overlay may write (relative to the app's root) */
    public const ALLOWED = ['src/', 'config/', 'vendor/jumphp/ide/src/', 'vendor/jumphp/ide/ui/'];

    public static function dir(string $data): string
    {
        return rtrim($data, '/\\') . '/overlay';
    }

    /** @return array{active: ?string, pending: bool, bad: list<string>, tries: int, crashed: bool} */
    public static function state(string $data): array
    {
        $json = is_file(self::dir($data) . '/state.json') ? json_decode((string)@file_get_contents(self::dir($data) . '/state.json'), true) : null;
        $json = is_array($json) ? $json : [];
        return ['active' => is_string($json['active'] ?? null) && preg_match('/^[0-9][0-9a-z.+-]*$/', $json['active']) ? $json['active'] : null,
            'pending' => (bool)($json['pending'] ?? false), 'bad' => array_values(array_filter((array)($json['bad'] ?? []), 'is_string')),
            'tries' => (int)($json['tries'] ?? 0), 'crashed' => (bool)($json['crashed'] ?? false)];
    }

    /** @param array{active: ?string, pending: bool, bad: list<string>, tries?: int, crashed?: bool} $state */
    private static function save(string $data, array $state): void
    {
        $state += ['tries' => 0, 'crashed' => false];
        @mkdir(self::dir($data), 0777, true);
        $tmp = self::dir($data) . '/state.json.' . getmypid();
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
        rename($tmp, self::dir($data) . '/state.json');
    }

    /** The `overlay_compat` number of a tree's config (game.yaml / project.yaml), 0 when there is none. */
    public static function compatOf(string $root): int
    {
        foreach (['config/project.yaml', 'config/game.yaml'] as $file) {
            if (is_file("$root/$file") && preg_match('/^\s*overlay_compat:\s*(\d+)/m', (string)file_get_contents("$root/$file"), $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    /** The `version` of a tree's config, '' when there is none. */
    public static function versionOf(string $root): string
    {
        foreach (['config/project.yaml', 'config/game.yaml'] as $file) {
            if (is_file("$root/$file") && preg_match('/^\s*version:\s*[\'"]?([0-9][0-9A-Za-z.\-+]*)/m', (string)file_get_contents("$root/$file"), $m)) {
                return $m[1];
            }
        }
        return '';
    }

    /**
     * Where the app runs from: $root itself, or the copy with the active overlay on top. Never throws: any trouble means $root.
     *
     * @param string $root the unpacked app (from the executable)
     * @param string $data the app's data folder
     */
    public static function resolve(string $root, string $data): string
    {
        try {
            if (getenv('JUMPHP_NO_OVERLAY') === '1') {
                return $root;
            }
            $state = self::state($data);
            $version = $state['active'];
            if ($version === null) {
                return $root;
            }
            // the executable is newer than (or as new as) the overlay: a new program was installed, the overlay would hide it behind its older
            // version (an endless "update / restart"): dropped
            $own = self::versionOf($root);
            if ($own !== '' && version_compare($own, $version, '>=')) {
                self::save($data, ['active' => null, 'pending' => false, 'bad' => $state['bad']]);
                return $root;
            }
            if ($state['pending']) {
                // the last start of this overlay never reached confirm(). A PHP fatal error (parse error, uncaught exception) is the overlay's fault: bad
                // at once. Anything else (the window that never showed, the program killed by the user, the computer off) is not known to be: it
                // gets MAX_TRIES starts in a row before it is dropped, so that a freeze that also happens without an overlay does not cost the update
                if ($state['crashed'] || $state['tries'] >= self::MAX_TRIES) {
                    self::save($data, ['active' => null, 'pending' => false, 'bad' => [...$state['bad'], $version]]);
                    fwrite(STDERR, "[overlay] $version did not start " . ($state['crashed'] ? '(a PHP fatal error)' : 'after ' . $state['tries'] . ' tries') . ": back to the program's own files\n");
                    return $root;
                }
            }
            $tree = self::dir($data) . "/$version/tree";
            $manifest = json_decode((string)@file_get_contents(self::dir($data) . "/$version/manifest.json"), true);
            if (!is_dir($tree) || !is_array($manifest) || (int)($manifest['compat'] ?? -1) !== self::compatOf($root) || in_array($version, $state['bad'], true)) {
                return $root;
            }
            $merged = "$root-ov-$version";
            if (!is_file("$merged/.unpacked")) {
                self::remove("$merged.tmp");
                self::copyTree($root, "$merged.tmp");
                foreach (self::files($tree) as $relative) {
                    $to = "$merged.tmp/$relative";
                    @mkdir(dirname($to), 0777, true);
                    @unlink($to);                                  // a hard link to the executable's own file: replaced, never written through
                    if (!copy("$tree/$relative", $to)) {
                        throw new \RuntimeException("cannot copy $relative");
                    }
                }
                foreach ((array)($manifest['delete'] ?? []) as $gone) {
                    if (is_string($gone) && self::allowed($gone)) {
                        @unlink("$merged.tmp/$gone");
                    }
                }
                touch("$merged.tmp/.unpacked");
                if (!@rename("$merged.tmp", $merged)) {
                    self::remove("$merged.tmp");                  // another copy of the app made it meanwhile
                }
            }
            if (!is_file("$merged/.unpacked")) {
                return $root;
            }
            self::save($data, ['active' => $version, 'pending' => true, 'bad' => $state['bad'], 'tries' => $state['pending'] ? $state['tries'] + 1 : 1, 'crashed' => false]);
            self::watch($data);
            self::clean($root, $data, $version);
            return $merged;
        } catch (\Throwable $e) {
            fwrite(STDERR, '[overlay] ' . $e->getMessage() . ": back to the program's own files\n");
            return $root;
        }
    }

    /** The app is up (its page loaded): the active overlay is good. */
    public static function confirm(string $data): void
    {
        $state = self::state($data);
        if ($state['pending']) {
            self::save($data, ['active' => $state['active'], 'pending' => false, 'bad' => $state['bad']]);
        }
    }

    /** A PHP fatal error while the overlay is pending is written down: it is the overlay's, the next start drops it. */
    private static function watch(string $data): void
    {
        register_shutdown_function(static function () use ($data): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                $state = self::state($data);
                if ($state['pending']) {
                    self::save($data, ['crashed' => true] + $state);
                }
            }
        });
    }

    /** The version that runs now (from an overlay), or null. */
    public static function running(string $data): ?string
    {
        $state = self::state($data);
        return $state['active'] !== null && !$state['pending'] ? $state['active'] : null;
    }

    public static function isBad(string $data, string $version): bool
    {
        return in_array($version, self::state($data)['bad'], true);
    }

    /**
     * Unpacks a downloaded overlay (a .tar or .tar.gz holding manifest.json + the files) and makes it the one to run at the next start.
     * Everything is checked first; nothing of the app changes (only <data>/overlay).
     *
     * @param array{version: string, compat: int, sha256: string} $expected what the signed update.json said
     */
    public static function install(string $data, string $archiveBytes, array $expected, int $ownCompat): void
    {
        if (!preg_match('/^[0-9][0-9a-z.+-]*$/', $expected['version'])) {
            throw new \RuntimeException('overlay: bad version');
        }
        if (!hash_equals(strtolower($expected['sha256']), hash('sha256', $archiveBytes))) {
            throw new \RuntimeException('overlay: the download does not match its signed SHA-256');
        }
        if ((int)$expected['compat'] !== $ownCompat || $ownCompat < 1) {
            throw new \RuntimeException('overlay: made for another executable (compat ' . $expected['compat'] . ', this one ' . $ownCompat . ')');
        }
        $work = self::dir($data) . "/{$expected['version']}.tmp" . getmypid();
        @mkdir($work, 0777, true);
        try {
            $file = "$work/archive.tar" . (str_starts_with($archiveBytes, "\x1f\x8b") ? '.gz' : '');
            file_put_contents($file, $archiveBytes);
            $tar = new \PharData($file);
            $manifest = isset($tar['manifest.json']) ? json_decode($tar['manifest.json']->getContent(), true) : null;
            if (!is_array($manifest) || ($manifest['version'] ?? null) !== $expected['version'] || (int)($manifest['compat'] ?? -1) !== $ownCompat || !is_array($manifest['files'] ?? null)) {
                throw new \RuntimeException('overlay: manifest.json does not match what was signed');
            }
            foreach ($manifest['files'] as $path => $sha) {
                if (!is_string($path) || !self::allowed($path) || !is_string($sha)) {
                    throw new \RuntimeException("overlay: a file outside what an overlay may hold: $path");
                }
                if (!isset($tar["files/$path"])) {
                    throw new \RuntimeException("overlay: $path is missing");
                }
                $content = $tar["files/$path"]->getContent();
                if (!hash_equals(strtolower($sha), hash('sha256', $content))) {
                    throw new \RuntimeException("overlay: $path does not match its SHA-256");
                }
                @mkdir(dirname("$work/tree/$path"), 0777, true);
                file_put_contents("$work/tree/$path", $content);
            }
            foreach ((array)($manifest['delete'] ?? []) as $gone) {
                if (!is_string($gone) || !self::allowed($gone)) {
                    throw new \RuntimeException('overlay: a deletion outside what an overlay may hold');
                }
            }
            unset($tar);
            @unlink($file);
            @unlink("$file.gz");
            file_put_contents("$work/manifest.json", json_encode($manifest));
            $final = self::dir($data) . "/{$expected['version']}";
            self::remove($final);
            if (!rename($work, $final)) {
                throw new \RuntimeException('overlay: cannot put it in place');
            }
        } catch (\Throwable $e) {
            self::remove($work);
            throw $e;
        }
        $state = self::state($data);
        self::save($data, ['active' => $expected['version'], 'pending' => false, 'bad' => array_values(array_diff($state['bad'], [$expected['version']]))]);
    }

    public static function allowed(string $path): bool
    {
        if ($path === '' || str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/') || str_contains($path, "\0")) {
            return false;
        }
        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> the files of a tree, relative */
    private static function files(string $dir): array
    {
        $out = [];
        $length = strlen(rtrim($dir, '/')) + 1;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $out[] = substr($file->getPathname(), $length);
            }
        }
        return $out;
    }

    /** A copy of a tree with hard links (the same files, no room taken), else real copies. */
    private static function copyTree(string $from, string $to): void
    {
        @mkdir($to, 0777, true);
        foreach (new \DirectoryIterator($from) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $source = $entry->getPathname();
            $target = "$to/" . $entry->getFilename();
            if ($entry->isDir() && !$entry->isLink()) {
                self::copyTree($source, $target);
            } elseif (!@link($source, $target) && !@copy($source, $target)) {
                throw new \RuntimeException('cannot copy ' . $entry->getFilename());
            }
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove("$path/$name");
                }
            }
            @rmdir($path);
        }
    }

    /** Old overlays and their copies go (the active one and the one before stay). */
    private static function clean(string $root, string $data, string $active): void
    {
        foreach (glob("$root-ov-*", GLOB_ONLYDIR) ?: [] as $dir) {
            if (!str_ends_with($dir, "-ov-$active")) {
                self::remove($dir);
            }
        }
        $versions = array_filter(glob(self::dir($data) . '/*', GLOB_ONLYDIR) ?: [], fn ($d) => !str_contains(basename($d), '.tmp'));
        usort($versions, fn ($a, $b) => version_compare(basename($b), basename($a)));
        foreach (array_slice($versions, 2) as $old) {
            if (basename($old) !== $active) {
                self::remove($old);
            }
        }
    }
}
