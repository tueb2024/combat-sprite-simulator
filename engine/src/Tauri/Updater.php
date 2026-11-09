<?php

namespace JumPHP\Tauri;

use JumPHP\Async\Task;
use JumPHP\Http\HttpResponse;

/**
 * Updates of an exported desktop app (bin/jumphp app:export / app:release, docs/tauri.md "Updates").
 *
 * bin/jumphp app:release <app> --url <where the files will be> exports the app and writes update.json next to the
 * executables: the version, and for each system its file's address, size and SHA-256, signed with the app's key
 * (ECDSA P-256, ~/.jumphp/keys/<id>-update.pem; its public half is config/update.pub, in the app). The app knows
 * where to look with game.yaml `app: {update: {url: https://.../update.json}}`.
 *
 *   $app->updater()->check()->then(function (?Update $update) use ($app) {
 *       if ($update !== null) {
 *           $update->install()->then(fn () => $app->updater()->relaunch());   // downloads, checks, replaces
 *       }
 *   });
 *
 * Only an exported app can replace itself (`php app.php` is not one: isInstallable() is false): its executable is
 * replaced by the newer one (Windows: the running one is renamed *.old first, removed at the next start; macOS:
 * the executable of the Mac's processor inside the .app). Nothing is installed whose signature or SHA-256 is wrong.
 */
final class Updater
{
    public function __construct(private readonly App $app)
    {
    }

    /** The address of update.json (game.yaml app.update.url; JUMPHP_UPDATE_URL tries another one), or null. */
    public function url(): ?string
    {
        $env = getenv('JUMPHP_UPDATE_URL');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $update = (array)$this->app->option('update', []);
        $url = $update['url'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /** The public key that signs the updates (config/update.pub, written by bin/jumphp app:release), or null. */
    public function publicKey(): ?string
    {
        $file = $this->app->config->path('config/update.pub');
        return is_file($file) ? (string)file_get_contents($file) : null;
    }

    public function version(): string
    {
        return (string)$this->app->config->get('version', '0.0.0');
    }

    /** This computer's entry in update.json: linux-x86_64, windows-x86_64, macos-arm64, macos-x86_64... */
    public static function platform(): string
    {
        $arch = strtolower(php_uname('m'));
        $arch = match ($arch) {
            'amd64', 'x64' => 'x86_64',
            'aarch64' => PHP_OS_FAMILY === 'Darwin' ? 'arm64' : 'aarch64',
            default => $arch,
        };
        $os = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            default => 'linux',
        };
        return "$os-$arch";
    }

    /** The executable to replace (an exported app), or null (`php app.php`). */
    public function executable(): ?string
    {
        $exe = defined('JUMPHP_EXECUTABLE') ? (string)constant('JUMPHP_EXECUTABLE') : '';
        return $exe !== '' && is_file($exe) ? $exe : null;
    }

    public function isInstallable(): bool
    {
        return $this->executable() !== null && is_writable(dirname((string)$this->executable()));
    }

    /** Settles with an Update newer than this version, or null (none, or no url); fails on a bad signature. */
    public function check(): Task
    {
        $result = new Task();
        $url = $this->url();
        if ($url === null) {
            $result->settle(null);
            return $result;
        }
        $this->app->http()->get($url)->then(function (HttpResponse $r) use ($result): void {
            try {
                if (!$r->ok()) {
                    throw new \RuntimeException("update.json: HTTP $r->status");
                }
                $result->settle($this->parse($r->body));
            } catch (\Throwable $e) {
                $result->settle(null, $e);
            }
        }, fn (\Throwable $e) => $result->settle(null, $e));
        return $result;
    }

    /**
     * update.json checked: its signature (with config/update.pub, or $publicKey), then the version.
     * Returns the Update for this computer when it is newer, null otherwise.
     */
    public function parse(string $json, ?string $publicKey = null, ?string $platform = null): ?Update
    {
        $publicKey ??= $this->publicKey();
        if ($publicKey === null) {
            throw new \RuntimeException('no config/update.pub: updates can not be checked (bin/jumphp app:release writes it)');
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['signed'] ?? null) || !is_string($data['signature'] ?? null)) {
            throw new \RuntimeException('update.json: not an update file');
        }
        $signature = base64_decode($data['signature'], true);
        $key = openssl_pkey_get_public($publicKey);
        if ($signature === false || $key === false || openssl_verify($data['signed'], $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('update.json: wrong signature');
        }
        $signed = json_decode($data['signed'], true);
        if (!is_array($signed) || !is_string($signed['version'] ?? null)) {
            throw new \RuntimeException('update.json: no version');
        }
        if (($signed['id'] ?? null) !== $this->app->config->id()) {
            throw new \RuntimeException('update.json: another app (' . json_encode($signed['id'] ?? null) . ')');
        }
        if (!version_compare($signed['version'], $this->version(), '>')) {
            return null;
        }
        $file = $signed['platforms'][$platform ?? self::platform()] ?? null;
        if (!is_array($file) || !is_string($file['url'] ?? null) || !is_string($file['sha256'] ?? null)) {
            return null;        // newer, but not for this system
        }
        return new Update($this, $signed['version'], (string)($signed['notes'] ?? ''), $file['url'], strtolower($file['sha256']),
            (int)($file['size'] ?? 0));
    }

    /** @internal Update::install(): downloads, checks and puts the new executable in place. Settles with true. */
    public function install(Update $update): Task
    {
        $result = new Task();
        $exe = $this->executable();
        if ($exe === null) {
            $result->settle(null, new \RuntimeException('not an exported app (bin/jumphp app:export): nothing to replace'));
            return $result;
        }
        $this->app->http()->request('GET', $update->url, null, [], 600)->then(function (HttpResponse $r) use ($update, $exe, $result): void {
            try {
                if (!$r->ok()) {
                    throw new \RuntimeException("download: HTTP $r->status");
                }
                self::replace($exe, $r->body, $update->sha256);
                $result->settle(true);
            } catch (\Throwable $e) {
                $result->settle(null, $e);
            }
        }, fn (\Throwable $e) => $result->settle(null, $e));
        return $result;
    }

    /** Puts $bytes in place of the executable $exe, when their SHA-256 is $sha256. */
    public static function replace(string $exe, string $bytes, string $sha256): void
    {
        if (!hash_equals(strtolower($sha256), hash('sha256', $bytes))) {
            throw new \RuntimeException('download: wrong SHA-256, nothing replaced');
        }
        $new = "$exe.new";
        if (@file_put_contents($new, $bytes) !== strlen($bytes)) {
            @unlink($new);
            throw new \RuntimeException("cannot write $new");
        }
        @chmod($new, 0755);
        if (PHP_OS_FAMILY === 'Windows') {
            // a running .exe can not be overwritten, but it can be renamed (the stub removes *.old at the next start)
            @unlink("$exe.old");
            if (!@rename($exe, "$exe.old")) {
                @unlink($new);
                throw new \RuntimeException("cannot move $exe aside");
            }
        }
        if (!@rename($new, $exe)) {
            PHP_OS_FAMILY === 'Windows' && @rename("$exe.old", $exe);
            @unlink($new);
            throw new \RuntimeException("cannot replace $exe");
        }
    }

    /** Starts the (new) executable and quits this one. */
    public function relaunch(): void
    {
        $exe = $this->executable();
        if ($exe === null) {
            throw new \RuntimeException('not an exported app: nothing to relaunch');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start "" ' . escapeshellarg($exe), 'r'));
        } else {
            // a second later, once this one has let go of its windows
            proc_open(['/bin/sh', '-c', 'sleep 1; exec "$0"', $exe], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        }
        $this->app->quit();
    }
}
