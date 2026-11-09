<?php

namespace JumPHP\Tauri;

/**
 * Linux: the system's WebView of desktop apps (WebKitGTK 4.1), installed when it is missing.
 *
 * Tauri's library needs libwebkit2gtk-4.1 (and what it brings: GTK 3, libsoup 3, JavaScriptCore). When it can not
 * be loaded, App::boot() asks here, BEFORE any window: the missing libraries (ldd), the distribution
 * (/etc/os-release), its package and its command; then the user is asked (a dialog: zenity / kdialog, or the
 * terminal the app was started from), and the package is installed with their rights (root, pkexec's password
 * window, or sudo in the terminal). Never silently.
 *
 *   JUMPHP_INSTALL=ask (default) | yes (install without asking: scripts, tests) | no (only say what to do)
 *
 * When it can not be done (declined, no password, no network, an immutable system, an unknown distribution), the
 * user gets the exact command to run, in a dialog and on stderr.
 */
final class LinuxWebView
{
    /** distribution (ID or ID_LIKE of /etc/os-release) => [package manager command, package] */
    private const PACKAGES = [
        'debian' => ['apt-get update && apt-get install -y %s', 'libwebkit2gtk-4.1-0'],
        'ubuntu' => ['apt-get update && apt-get install -y %s', 'libwebkit2gtk-4.1-0'],
        'fedora' => ['dnf install -y %s', 'webkit2gtk4.1'],
        'rhel' => ['dnf install -y %s', 'webkit2gtk4.1'],
        'arch' => ['pacman -S --needed --noconfirm %s', 'webkit2gtk-4.1'],
        'opensuse' => ['zypper --non-interactive install %s', 'libwebkit2gtk-4_1-0'],
        'suse' => ['zypper --non-interactive install %s', 'libwebkit2gtk-4_1-0'],
    ];

    /** @var list<string> what the last ask() printed / showed (tests) */
    public static array $said = [];

    /** The shared libraries $library needs that this system does not have (ldd), e.g. ['libwebkit2gtk-4.1.so.0']. */
    public static function missingLibraries(string $library): array
    {
        $out = @shell_exec('ldd ' . escapeshellarg($library) . ' 2>/dev/null');
        if (!is_string($out)) {
            return [];
        }
        preg_match_all('/^\s*(\S+)\s+=>\s+not found/m', $out, $m);
        return array_values(array_unique($m[1]));
    }

    /** True when what is missing is the WebView (WebKitGTK and the libraries its package brings). */
    public static function isWebViewMissing(array $missing): bool
    {
        foreach ($missing as $lib) {
            if (preg_match('/^lib(webkit2gtk|javascriptcoregtk|soup-3|gtk-3|gdk-3)/', $lib)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The command that installs WebKitGTK on this distribution, without sudo / pkexec ('' when unknown).
     * @param array<string, string> $osRelease the keys of /etc/os-release (tests); null: this system's
     */
    public static function installCommand(?array $osRelease = null): string
    {
        $os = $osRelease ?? self::osRelease();
        $ids = array_merge([strtolower($os['ID'] ?? '')], preg_split('/\s+/', strtolower($os['ID_LIKE'] ?? '')) ?: []);
        foreach ($ids as $id) {
            foreach (self::PACKAGES as $family => [$command, $package]) {
                if ($id === $family || str_starts_with($id, $family)) {
                    return sprintf($command, $package);
                }
            }
        }
        return '';
    }

    /** @return array<string, string> /etc/os-release */
    public static function osRelease(): array
    {
        $os = [];
        foreach (['/etc/os-release', '/usr/lib/os-release'] as $file) {
            if (is_readable($file)) {
                foreach ((array)file($file, FILE_IGNORE_NEW_LINES) as $line) {
                    if (preg_match('/^([A-Z_]+)=(.*)$/', (string)$line, $m)) {
                        $os[$m[1]] = trim($m[2], "\"'");
                    }
                }
                break;
            }
        }
        return $os;
    }

    /**
     * Offers to install WebKitGTK for $library (Tauri's) and does it when the user agrees.
     * Returns true when it was installed (the caller loads the library again).
     */
    public static function offer(string $library, string $appTitle): bool
    {
        $missing = self::missingLibraries($library);
        if ($missing !== [] && !self::isWebViewMissing($missing)) {
            return false;       // something else is missing: the loader's own error says what
        }
        $command = self::installCommand();
        $mode = strtolower((string)(getenv('JUMPHP_INSTALL') ?: 'ask'));
        if (is_file('/run/ostree-booted')) {
            self::tell($appTitle, "$appTitle needs WebKitGTK 4.1, which this system (an immutable one) can not install by itself.\n"
                . "Install it with rpm-ostree install webkit2gtk4.1 (then restart the computer), or run the app in a toolbox.");
            return false;
        }
        if ($command === '') {
            $os = self::osRelease()['PRETTY_NAME'] ?? 'this distribution';
            self::tell($appTitle, "$appTitle needs WebKitGTK 4.1 (libwebkit2gtk-4.1), which is not installed.\n"
                . "Install it with the package manager of $os, then start the app again.");
            return false;
        }
        if ($mode === 'no' || ($mode !== 'yes' && !self::ask($appTitle, "$appTitle needs WebKitGTK 4.1 to show its windows (about 40 MB with what it brings).\n\nInstall it now?\n\n$command"))) {
            self::tell($appTitle, "$appTitle needs WebKitGTK 4.1. Install it with:\n\nsudo sh -c '$command'\n\nthen start the app again.");
            return false;
        }
        $code = self::run($command);
        if ($code !== 0) {
            self::tell($appTitle, "WebKitGTK could not be installed (exit code $code). Install it with:\n\nsudo sh -c '$command'\n\nthen start the app again.");
            return false;
        }
        return true;
    }

    /** Runs $command as root: directly when root, pkexec (the password window) in a desktop, sudo in a terminal. */
    private static function run(string $command): int
    {
        $root = function_exists('posix_geteuid') && posix_geteuid() === 0;
        $terminal = self::inTerminal();
        $argv = match (true) {
            $root => ['sh', '-c', $command],
            self::graphical() && self::has('pkexec') => ['pkexec', 'sh', '-c', $command],
            $terminal && self::has('sudo') => ['sudo', 'sh', '-c', $command],
            self::has('pkexec') => ['pkexec', 'sh', '-c', $command],
            default => null,
        };
        if ($argv === null) {
            return 127;
        }
        // its output goes where ours goes (the terminal, if any); no input but the terminal's (sudo's password)
        $process = proc_open($argv, [0 => $terminal ? STDIN : ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR], $pipes);
        return is_resource($process) ? proc_close($process) : 127;
    }

    /** A yes / no question: the terminal the app was started from, else a dialog of the desktop. */
    private static function ask(string $title, string $question): bool
    {
        self::$said[] = $question;
        if (self::inTerminal()) {
            fwrite(STDERR, "\n$question\n[Y/n] ");
            $answer = strtolower(trim((string)fgets(STDIN)));
            return $answer === '' || $answer[0] === 'y' || $answer[0] === 'o';
        }
        if (self::graphical()) {
            foreach ([['zenity', '--question', '--title', $title, '--text', $question, '--width', '460'],
                      ['kdialog', '--title', $title, '--yesno', $question]] as $argv) {
                if (self::has($argv[0])) {
                    $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                    return is_resource($process) && proc_close($process) === 0;
                }
            }
        }
        fwrite(STDERR, "$question\n(no terminal and no dialog to ask: JUMPHP_INSTALL=yes installs without asking)\n");
        return false;
    }

    /** A message on stderr, and in a dialog when the app was not started from a terminal. */
    private static function tell(string $title, string $message): void
    {
        self::$said[] = $message;
        fwrite(STDERR, "[jumphp] $message\n");
        if (!self::inTerminal() && self::graphical()) {
            foreach ([['zenity', '--warning', '--title', $title, '--text', $message, '--width', '460'],
                      ['kdialog', '--title', $title, '--sorry', $message],
                      ['xmessage', '-center', $message]] as $argv) {
                if (self::has($argv[0])) {
                    $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
                    is_resource($process) && proc_close($process);
                    return;
                }
            }
        }
    }

    private static function inTerminal(): bool
    {
        return defined('STDIN') && function_exists('posix_isatty') && @posix_isatty(STDIN);
    }

    private static function graphical(): bool
    {
        return (string)getenv('DISPLAY') !== '' || (string)getenv('WAYLAND_DISPLAY') !== '';
    }

    private static function has(string $program): bool
    {
        foreach (explode(':', (string)getenv('PATH') ?: '/usr/bin:/bin') as $dir) {
            if ($dir !== '' && is_executable("$dir/$program")) {
                return true;
            }
        }
        return false;
    }
}
