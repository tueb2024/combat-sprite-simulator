<?php

namespace JumPHP\Tests\Tauri;

use JumPHP\Native\Autostart;
use JumPHP\Native\Native;
use JumPHP\Tauri\App;
use JumPHP\Tauri\FakeRuntime;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

final class AutostartTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/jumphp-autostart-' . bin2hex(random_bytes(4));
        mkdir($this->home);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->home));
        App::forgetCurrent();
        Native::setProvider(null);
    }

    private function start(string $os, ?string $exe, array $argv = [], ?callable $runner = null): Autostart
    {
        return new Autostart('notes', 'My Notes', 'net.jumphp.notes', $exe, $os, $this->home, $argv, $runner);
    }

    public function testLinuxWritesAnAutostartDesktopEntry(): void
    {
        $start = $this->start('Linux', '/opt/My Apps/notes-linux-x86_64');
        self::assertTrue($start->isSupported());
        self::assertFalse($start->isEnabled());
        self::assertTrue($start->set(true));
        self::assertTrue($start->isEnabled());
        $entry = (string)file_get_contents("$this->home/.config/autostart/notes.desktop");
        self::assertStringContainsString("Name=My Notes\n", $entry);
        self::assertStringContainsString('Exec="/opt/My Apps/notes-linux-x86_64" --autostart', $entry);
        self::assertStringContainsString('X-GNOME-Autostart-enabled=true', $entry);
        self::assertTrue($start->set(false));
        self::assertFalse($start->isEnabled());
        self::assertTrue($start->disable(), 'off twice is fine');
    }

    public function testMacosWritesALaunchAgentThatOpensTheApp(): void
    {
        $start = $this->start('Darwin', '/Applications/My Notes.app/Contents/MacOS/notes-arm64');
        self::assertSame(['/usr/bin/open', '-a', '/Applications/My Notes.app', '--args', '--autostart'], $start->command());
        self::assertTrue($start->enable());
        $plist = (string)file_get_contents("$this->home/Library/LaunchAgents/net.jumphp.notes.plist");
        self::assertStringContainsString('<string>net.jumphp.notes</string>', $plist);
        self::assertStringContainsString('<string>/Applications/My Notes.app</string>', $plist);
        self::assertStringContainsString('<key>RunAtLoad</key>', $plist);
        self::assertNotFalse(simplexml_load_string($plist), 'a valid plist');
        self::assertTrue($start->disable());
        self::assertFileDoesNotExist("$this->home/Library/LaunchAgents/net.jumphp.notes.plist");
    }

    public function testWindowsUsesTheRunKeyOfTheUser(): void
    {
        $registry = [];
        $calls = [];
        $reg = function (array $cmd) use (&$registry, &$calls): array {
            $calls[] = $cmd;
            return match ($cmd[1]) {
                'add' => [($registry[$cmd[4]] = $cmd[8]) !== '' ? 0 : 1, ''],
                'query' => [isset($registry[$cmd[4]]) ? 0 : 1, ''],
                'delete' => [(function () use (&$registry, $cmd) { unset($registry[$cmd[4]]); return 0; })(), ''],
            };
        };
        $start = $this->start('Windows', 'C:\\Users\\ada\\notes-windows-x86_64.exe', [], $reg);
        self::assertFalse($start->isEnabled());
        self::assertTrue($start->enable());
        self::assertSame(['notes' => '"C:\\Users\\ada\\notes-windows-x86_64.exe" --autostart'], $registry);
        self::assertSame('HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run', $calls[1][2]);
        self::assertTrue($start->isEnabled());
        self::assertTrue($start->disable());
        self::assertSame([], $registry);
    }

    public function testOnlyAnExportedAppCan(): void
    {
        $start = $this->start('Linux', null);
        self::assertFalse($start->isSupported());
        self::assertFalse($start->enable());
        self::assertFalse($start->isEnabled());
        self::assertFileDoesNotExist("$this->home/.config/autostart/notes.desktop");
    }

    public function testTheFlagTellsItStartedAtLogin(): void
    {
        self::assertTrue($this->start('Linux', '/x', ['/x', '--autostart'])->startedAtLogin());
        self::assertFalse($this->start('Linux', '/x', ['/x'])->startedAtLogin());
    }

    public function testStartedAtLoginTheMainWindowStartsHiddenWhenTheConfigSaysSo(): void
    {
        Loop::set(new StreamSelectLoop());
        $root = "$this->home/app";
        exec('cp -r ' . escapeshellarg(__DIR__ . '/../fixtures/app') . ' ' . escapeshellarg($root));
        $yaml = (string)file_get_contents("$root/config/game.yaml");
        file_put_contents("$root/config/game.yaml", preg_replace('/^(\s*)app:\s*$/m', "\$1app:\n\$1  autostart: {hidden: true}", $yaml, 1, $n));
        self::assertSame(1, $n, 'the fixture has an app: section');
        $argv = $_SERVER['argv'];
        $_SERVER['argv'] = ['app', '--autostart'];
        try {
            $runtime = new FakeRuntime();
            $app = App::boot($root, $runtime);
            self::assertTrue($app->autostart()->startedAtLogin());
            self::assertFalse($runtime->sentOf('window.create')[0]['visible']);
            App::forgetCurrent();
            $_SERVER['argv'] = ['app'];
            $runtime = new FakeRuntime();
            App::boot($root, $runtime);
            self::assertArrayNotHasKey('visible', $runtime->sentOf('window.create')[0]);
        } finally {
            $_SERVER['argv'] = $argv;
        }
    }
}
