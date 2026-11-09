<?php

namespace JumPHP\Native;

/**
 * An exported app that starts with the computer, when the user logs in (docs/tauri.md "Starting with the computer"):
 * - Windows: the value <id> of HKCU\Software\Microsoft\Windows\CurrentVersion\Run (`reg.exe`);
 * - macOS: a LaunchAgent, ~/Library/LaunchAgents/<identifier>.plist (RunAtLoad), that opens the .app;
 * - Linux: ~/.config/autostart/<id>.desktop (freedesktop autostart: GNOME, KDE, Xfce...).
 * The app is started with `--autostart`: startedAtLogin() is true, and with game.yaml `app: {autostart: {hidden:
 * true}}` its main window starts hidden (an app of the tray). For the user only, no administrator rights.
 *
 *     $start = $app->autostart();
 *     if ($start->isSupported()) { $start->set(!$start->isEnabled()); }
 *
 * Only an exported app can (`php app.php` is no program to start: isSupported() is false).
 */
final class Autostart
{
    public const FLAG = '--autostart';
    private const RUN_KEY = 'HKCU\Software\Microsoft\Windows\CurrentVersion\Run';

    /** @var \Closure(list<string>): array{0: int, 1: string} runs a program, returns its exit code and output */
    private \Closure $runner;

    /**
     * @param string|null $executable the exported app's executable (JUMPHP_EXECUTABLE), null: not an exported app
     * @param string $os PHP_OS_FAMILY (Windows, Darwin, Linux)
     * @param list<string> $argv the command line the app was started with
     * @param (callable(list<string>): array{0: int, 1: string})|null $runner
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $identifier,
        private readonly ?string $executable,
        private readonly string $os = PHP_OS_FAMILY,
        private readonly ?string $home = null,
        private readonly array $argv = [],
        ?callable $runner = null,
    ) {
        $this->runner = $runner !== null ? $runner(...) : self::run(...);
    }

    /** This app can start with the computer: an exported app on Windows, macOS or Linux. */
    public function isSupported(): bool
    {
        return $this->executable !== null && in_array($this->os, ['Windows', 'Darwin', 'Linux'], true)
            && ($this->os === 'Windows' || $this->homeDir() !== '');
    }

    /** Started by the computer at login (the `--autostart` of the entry this class wrote). */
    public function startedAtLogin(): bool
    {
        return in_array(self::FLAG, $this->argv, true);
    }

    public function isEnabled(): bool
    {
        if (!$this->isSupported()) {
            return false;
        }
        return match ($this->os) {
            'Windows' => ($this->runner)(['reg', 'query', self::RUN_KEY, '/v', $this->id])[0] === 0,
            default => is_file($this->entryFile()),
        };
    }

    /** Starts the app with the computer, or not. @return bool done (false: not supported, or the system refused) */
    public function set(bool $on): bool
    {
        return $on ? $this->enable() : $this->disable();
    }

    public function enable(): bool
    {
        if (!$this->isSupported()) {
            return false;
        }
        if ($this->os === 'Windows') {
            $command = '"' . $this->executable . '" ' . self::FLAG;
            return ($this->runner)(['reg', 'add', self::RUN_KEY, '/v', $this->id, '/t', 'REG_SZ', '/d', $command, '/f'])[0] === 0;
        }
        $file = $this->entryFile();
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) {
            return false;
        }
        return @file_put_contents($file, $this->os === 'Darwin' ? $this->launchAgent() : $this->desktopEntry()) !== false;
    }

    public function disable(): bool
    {
        if (!$this->isSupported()) {
            return false;
        }
        if ($this->os === 'Windows') {
            return !$this->isEnabled() || ($this->runner)(['reg', 'delete', self::RUN_KEY, '/v', $this->id, '/f'])[0] === 0;
        }
        return !is_file($this->entryFile()) || @unlink($this->entryFile());
    }

    /** macOS / Linux: the file that starts the app at login (Windows: none, a value of the registry). */
    public function entryFile(): string
    {
        return match ($this->os) {
            'Darwin' => $this->homeDir() . "/Library/LaunchAgents/$this->identifier.plist",
            default => $this->homeDir() . "/.config/autostart/$this->id.desktop",
        };
    }

    /** What the computer starts: the .app (macOS, through `open`), else the executable, with --autostart. @return list<string> */
    public function command(): array
    {
        $exe = (string)$this->executable;
        if ($this->os === 'Darwin' && preg_match('#^(.*?\.app)/Contents/MacOS/#', $exe, $m)) {
            return ['/usr/bin/open', '-a', $m[1], '--args', self::FLAG];
        }
        return [$exe, self::FLAG];
    }

    private function launchAgent(): string
    {
        $e = fn (string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES);
        $args = implode('', array_map(fn ($a) => "\n        <string>{$e($a)}</string>", $this->command()));
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>{$e($this->identifier)}</string>
    <key>ProgramArguments</key>
    <array>$args
    </array>
    <key>RunAtLoad</key>
    <true/>
    <key>ProcessType</key>
    <string>Interactive</string>
</dict>
</plist>

XML;
    }

    private function desktopEntry(): string
    {
        // the Exec key's quoting (freedesktop): arguments with spaces in double quotes, \ " ` $ escaped
        $exec = implode(' ', array_map(fn ($a) => preg_match('/[\s"\\\\`$]/', $a) ? '"' . addcslashes($a, '"\\`$') . '"' : $a, $this->command()));
        $name = str_replace("\n", ' ', $this->name);
        return "[Desktop Entry]\nType=Application\nName=$name\nExec=$exec\nTerminal=false\nX-GNOME-Autostart-enabled=true\n";
    }

    private function homeDir(): string
    {
        return rtrim((string)($this->home ?? (getenv('HOME') ?: '')), '/');
    }

    /** @param list<string> $command @return array{0: int, 1: string} */
    private static function run(array $command): array
    {
        $process = @proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return [-1, ''];
        }
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $out];
    }
}
