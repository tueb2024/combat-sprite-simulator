<?php

namespace JumPHP\Tests\Tauri;

use JumPHP\Async\Task;
use JumPHP\Native\Clipboard;
use JumPHP\Native\Dialog;
use JumPHP\Native\FilePicker;
use JumPHP\Native\Native;
use JumPHP\Native\Notification;
use JumPHP\Native\NullProvider;
use JumPHP\Native\Shell;
use JumPHP\Native\UnsupportedFeature;
use JumPHP\Tauri\App;
use JumPHP\Tauri\EmbeddedGame;
use JumPHP\Tauri\Updater;
use JumPHP\Tauri\FakeRuntime;
use JumPHP\Tauri\LinuxWebView;
use JumPHP\Tauri\NativeEvent;
use JumPHP\Tauri\TauriRuntime;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;

final class AppTest extends TestCase
{
    private FakeRuntime $runtime;
    private App $app;

    protected function setUp(): void
    {
        Loop::set(new StreamSelectLoop());
        Native::setProvider(null);
        $this->runtime = new FakeRuntime();
        $this->app = App::boot(__DIR__ . '/../fixtures/app', $this->runtime);
        $this->runtime->start([], fn () => $this->app->frame());
    }

    protected function tearDown(): void
    {
        Native::setProvider(null);
        App::forgetCurrent();
        Loop::stop();
    }

    private function invoke(string $name, mixed $payload = null, int $id = 1): ?array
    {
        $this->runtime->push(['type' => 'invoke', 'id' => $id, 'name' => $name, 'payload' => $payload, 'window' => 'main']);
        $this->runtime->frame();
        foreach ($this->runtime->sentOf('invoke.resolve') as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        return null;
    }

    public function testBootOpensTheMainWindowFromTheConfig(): void
    {
        $create = $this->runtime->sentOf('window.create')[0];
        self::assertSame('main', $create['window']);
        self::assertSame(640, $create['width']);
        self::assertSame('Fixture App', $create['title']);
        self::assertStringEndsWith('/webview', $create['data_directory']);
        self::assertStringEndsWith('fixtures/app/ui', $this->app->pagesDir());
    }

    public function testRunGivesTheRuntimeTheAppIdentity(): void
    {
        $runtime = new FakeRuntime(3);
        $app = App::boot(__DIR__ . '/../fixtures/app', $runtime);
        self::assertSame(0, $app->run());
        self::assertSame('net.jumphp.fixtureapp', $runtime->config['identifier']);
        self::assertSame('Fixture App', $runtime->config['product_name']);
        self::assertSame(3, $app->frameCount());
    }

    public function testACommandAnswersThePage(): void
    {
        $this->app->command('add', fn (array $p) => $p['a'] + $p['b']);
        $reply = $this->invoke('add', ['a' => 2, 'b' => 3]);
        self::assertTrue($reply['ok']);
        self::assertSame(5, $reply['result']);
        self::assertSame('main', $reply['window']);
    }

    public function testAnUnknownCommandOrAnExceptionRejects(): void
    {
        self::assertFalse($this->invoke('nope', null, 1)['ok']);
        $this->app->command('boom', fn () => throw new \DomainException('bad input'));
        $reply = $this->invoke('boom', null, 2);
        self::assertFalse($reply['ok']);
        self::assertSame('bad input', $reply['result']['message']);
        self::assertSame(\DomainException::class, $reply['result']['class']);
    }

    public function testATaskAnswersWhenItEnds(): void
    {
        $task = new Task();
        $this->app->command('later', fn () => $task);
        self::assertNull($this->invoke('later'));
        $task->settle(['done' => true]);
        $replies = $this->runtime->sentOf('invoke.resolve');
        self::assertSame(['done' => true], end($replies)['result']);
    }

    public function testPageMessagesAndLoadedReachPhp(): void
    {
        $got = null;
        $this->app->on('page:hello', function ($payload, string $window) use (&$got) {
            $got = [$payload, $window];
        });
        $this->runtime->push(['type' => 'page', 'name' => 'hello', 'payload' => ['x' => 1], 'window' => 'main']);
        $this->runtime->push(['type' => 'page', 'name' => '__loaded', 'payload' => 'tauri://localhost', 'window' => 'main']);
        $this->runtime->frame();
        self::assertSame([['x' => 1], 'main'], $got);
        self::assertTrue($this->app->window()->isLoaded());
    }

    public function testWindowCommandsAndSendToThePage(): void
    {
        $this->app->window()->setTitle('T')->setSize(800, 600)->send('tick', [1]);
        self::assertSame('T', $this->runtime->sentOf('window.set_title')[0]['title']);
        self::assertSame(['cmd' => 'webview.emit', 'window' => 'main', 'name' => 'tick', 'payload' => [1]], $this->runtime->sentOf('webview.emit')[0]);
    }

    public function testCloseRequestedClosesUnlessPrevented(): void
    {
        $this->runtime->push(['type' => 'window', 'window' => 'main', 'event' => 'close-requested']);
        $this->runtime->frame();
        self::assertCount(1, $this->runtime->sentOf('window.close'));

        $this->app->window()->on('close-requested', fn (NativeEvent $e) => $e->preventDefault());
        $this->runtime->push(['type' => 'window', 'window' => 'main', 'event' => 'close-requested']);
        $this->runtime->frame();
        self::assertCount(1, $this->runtime->sentOf('window.close'));
    }

    public function testTheLastWindowGoneQuitsUnlessPrevented(): void
    {
        $this->runtime->push(['type' => 'window', 'window' => 'main', 'event' => 'destroyed']);
        $this->runtime->push(['type' => 'exit-requested']);
        $this->runtime->frame();
        self::assertSame([], $this->app->windows());
        self::assertSame(0, $this->runtime->exitCode());
    }

    public function testNativeDialogsGoThroughTauriAndComeBack(): void
    {
        self::assertSame('tauri', Native::name());
        $answer = 'none';
        Dialog::confirm('Sure?', 'App', 'Yes', 'No')->then(function ($yes) use (&$answer) {
            $answer = $yes;
        });
        $cmd = $this->runtime->sentOf('dialog.confirm')[0];
        self::assertSame(['Sure?', 'Yes', 'No', 'main'], [$cmd['message'], $cmd['ok'], $cmd['cancel'], $cmd['window']]);
        $this->runtime->push(['type' => 'dialog', 'id' => $cmd['id'], 'result' => true]);
        $this->runtime->frame();
        self::assertTrue($answer);

        FilePicker::open(['filters' => ['Images' => ['.png', '*.jpg'], 'All' => ['*']], 'multiple' => true]);
        $open = $this->runtime->sentOf('dialog.open')[0];
        self::assertSame(['Images' => ['png', 'jpg'], 'All' => ['*']], $open['filters']);
        self::assertTrue($open['multiple']);
        self::assertTrue(Notification::show('Hi', 'there'));
        self::assertTrue(Shell::openUrl('https://jumphp.net'));
        self::assertFalse(Shell::openUrl('file:///etc/passwd'));
    }

    public function testBuiltInNativeCommandsForPages(): void
    {
        $this->invoke('native.message', ['message' => 'Hi'], 7);
        self::assertSame('Hi', $this->runtime->sentOf('dialog.message')[0]['message']);
        $reply = $this->invoke('native.notify', ['title' => 'T'], 8);
        self::assertTrue($reply['result']);
    }

    public function testAnotherTargetSaysWhatItLacks(): void
    {
        Native::setProvider(new NullProvider());
        self::assertFalse(Native::supports('open'));
        $error = null;
        FilePicker::open()->then(fn () => null, function (\Throwable $e) use (&$error) {
            $error = $e;
        });
        self::assertInstanceOf(UnsupportedFeature::class, $error);
        self::assertFalse(Notification::show('x'));
    }

    public function testTheLibraryIsFoundFromTheEnvironment(): void
    {
        putenv('JUMPHP_TAURI_LIB=' . __FILE__);
        try {
            self::assertSame(__FILE__, TauriRuntime::library('/nowhere'));
        } finally {
            putenv('JUMPHP_TAURI_LIB');
        }
        putenv('JUMPHP_TAURI_LIB=/nowhere/lib.so');
        try {
            self::assertNull(TauriRuntime::library('/nowhere'));
        } finally {
            putenv('JUMPHP_TAURI_LIB');
        }
        self::assertMatchesRegularExpression('/^(linux|windows|macos)-/', TauriRuntime::platform());
    }

    public function testTheClipboardGoesThroughTauri(): void
    {
        self::assertTrue(Clipboard::copy('hi'));
        self::assertSame('hi', $this->runtime->sentOf('clipboard.write')[0]['text']);
        $text = null;
        Clipboard::paste()->then(function ($t) use (&$text) {
            $text = $t;
        });
        $read = $this->runtime->sentOf('clipboard.read')[0];
        $this->runtime->push(['type' => 'result', 'id' => $read['id'], 'result' => 'from the system']);
        $this->runtime->frame();
        self::assertSame('from the system', $text);
    }

    public function testANativeFailureFailsItsTask(): void
    {
        $error = null;
        Clipboard::paste()->then(fn () => null, function (\Throwable $e) use (&$error) {
            $error = $e->getMessage();
        });
        $read = $this->runtime->sentOf('clipboard.read')[0];
        $this->runtime->push(['type' => 'result', 'id' => $read['id'], 'error' => 'no clipboard']);
        $this->runtime->frame();
        self::assertSame('no clipboard', $error);
    }

    public function testATrayIconAndItsMenu(): void
    {
        $picked = [];
        $this->app->tray('main', ['tooltip' => 'T', 'menu' => [['id' => 'open', 'text' => 'Open'], '-', ['id' => 'quit']]])
            ->on('menu', function (string $item) use (&$picked) {
                $picked[] = $item;
            });
        $cmd = $this->runtime->sentOf('tray.create')[0];
        self::assertSame([['id' => 'open', 'text' => 'Open', 'enabled' => true], ['separator' => true], ['id' => 'quit', 'text' => 'quit', 'enabled' => true]], $cmd['menu']);
        $this->runtime->push(['type' => 'tray', 'tray' => 'main', 'event' => 'menu', 'item' => 'quit']);
        $this->runtime->frame();
        self::assertSame(['quit'], $picked);
    }

    public function testMenusAreSentAndTheirClicksComeBack(): void
    {
        $this->app->setMenu([['text' => 'File', 'items' => [['id' => 'open', 'accelerator' => 'CmdOrCtrl+O'], '-', ['role' => 'quit']]]]);
        $cmd = $this->runtime->sentOf('menu.set')[0];
        self::assertSame('*', $cmd['window']);
        self::assertSame([['text' => 'File', 'items' => [['id' => 'open', 'accelerator' => 'CmdOrCtrl+O'], ['separator' => true], ['role' => 'quit']]]], $cmd['menu']);

        $this->app->createWindow('tools');
        $this->app->window('tools')->setMenu([['id' => 'grid', 'text' => 'Grid', 'checked' => false]]);
        self::assertSame('tools', $this->runtime->sentOf('menu.set')[1]['window']);

        $seen = [];
        $this->app->on('menu', function (string $item, string $window, ?bool $checked) use (&$seen) {
            $seen[] = [$item, $window, $checked];
        });
        $this->app->window('tools')->on('menu', function (string $item, ?bool $checked) use (&$seen) {
            $seen[] = ['tools:' . $item, $checked];
        });
        $this->runtime->push(['type' => 'menu', 'window' => '*', 'item' => 'open', 'checked' => null]);
        $this->runtime->push(['type' => 'menu', 'window' => 'tools', 'item' => 'grid', 'checked' => true]);
        $this->runtime->frame();
        self::assertSame([['open', '*', null], ['tools:grid', true], ['grid', 'tools', true]], $seen);

        $this->app->window('tools')->setMenuItem('grid', ['checked' => false, 'bogus' => 1]);
        self::assertSame(['cmd' => 'menu.set_item', 'window' => 'tools', 'item' => 'grid', 'checked' => false], $this->runtime->sentOf('menu.set_item')[0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->setMenu([['text' => 'no id']]);
    }

    public function testUpdatesAreCheckedThenReplaceTheExecutable(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        self::assertNotFalse($key);
        $public = (string)openssl_pkey_get_details($key)['key'];
        $manifest = function (array $signed) use ($key): string {
            $text = (string)json_encode($signed);
            openssl_sign($text, $sig, $key, OPENSSL_ALGO_SHA256);
            return (string)json_encode(['signed' => $text, 'signature' => base64_encode($sig)]);
        };
        $updater = $this->app->updater();
        $id = $this->app->config->id();
        $newer = ['id' => $id, 'version' => '99.0.0', 'notes' => 'Faster', 'platforms' => ['linux-x86_64' => ['url' => 'https://x/a', 'size' => 3, 'sha256' => hash('sha256', 'new')]]];

        $update = $updater->parse($manifest($newer), $public, 'linux-x86_64');
        self::assertNotNull($update);
        self::assertSame(['99.0.0', 'Faster', 'https://x/a'], [$update->version, $update->notes, $update->url]);
        self::assertNull($updater->parse($manifest($newer), $public, 'windows-x86_64'), 'nothing for this system');
        self::assertNull($updater->parse($manifest(['version' => '0.0.1'] + $newer), $public, 'linux-x86_64'), 'not newer');

        $forged = json_decode($manifest($newer), true);
        $forged['signed'] = str_replace('99.0.0', '98.0.0', $forged['signed']);
        try {
            $updater->parse((string)json_encode($forged), $public, 'linux-x86_64');
            self::fail('a changed update.json was accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('wrong signature', $e->getMessage());
        }
        try {
            $updater->parse($manifest(['id' => 'another'] + $newer), $public, 'linux-x86_64');
            self::fail("another app's update was accepted");
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('another app', $e->getMessage());
        }

        $exe = sys_get_temp_dir() . '/jumphp-updater-' . getmypid();
        file_put_contents($exe, 'old');
        try {
            Updater::replace($exe, 'new!', hash('sha256', 'new'));
            self::fail('a download with a wrong SHA-256 was installed');
        } catch (\RuntimeException $e) {
            self::assertSame('old', file_get_contents($exe));
        }
        Updater::replace($exe, 'new', hash('sha256', 'new'));
        self::assertSame('new', file_get_contents($exe));
        self::assertTrue(is_executable($exe));
        @unlink($exe);
        self::assertFalse($updater->isInstallable(), 'php app.php is not an exported app');
    }

    public function testAnEmbeddedGameGetsThePagesKeysAsSdlKeys(): void
    {
        \SDL_Init(\SDL_INIT_VIDEO);
        self::assertSame(\SDLK_LEFT, EmbeddedGame::sdlKey('ArrowLeft', 'ArrowLeft'));
        self::assertSame(\SDLK_RETURN, EmbeddedGame::sdlKey('Enter', 'Enter'));
        self::assertSame(\SDLK_SPACE, EmbeddedGame::sdlKey(' ', 'Space'));
        self::assertSame(\SDLK_LSHIFT, EmbeddedGame::sdlKey('Shift', 'ShiftLeft'));
        self::assertSame(\SDLK_F11, EmbeddedGame::sdlKey('F11', 'F11'));
        self::assertSame(\SDLK_KP_8, EmbeddedGame::sdlKey('8', 'Numpad8'));
        self::assertSame(ord('z'), EmbeddedGame::sdlKey('Z', 'KeyZ'));
        self::assertSame(ord('5'), EmbeddedGame::sdlKey('5', 'Digit5'));
        self::assertSame(0, EmbeddedGame::sdlKey('Unidentified', ''));
    }

    public function testTheWebViewPackageOfEachLinuxDistribution(): void
    {
        self::assertSame('apt-get update && apt-get install -y libwebkit2gtk-4.1-0', LinuxWebView::installCommand(['ID' => 'debian']));
        self::assertSame('apt-get update && apt-get install -y libwebkit2gtk-4.1-0', LinuxWebView::installCommand(['ID' => 'linuxmint', 'ID_LIKE' => 'ubuntu debian']));
        self::assertSame('dnf install -y webkit2gtk4.1', LinuxWebView::installCommand(['ID' => 'fedora']));
        self::assertSame('dnf install -y webkit2gtk4.1', LinuxWebView::installCommand(['ID' => 'rocky', 'ID_LIKE' => 'rhel centos fedora']));
        self::assertSame('pacman -S --needed --noconfirm webkit2gtk-4.1', LinuxWebView::installCommand(['ID' => 'manjaro', 'ID_LIKE' => 'arch']));
        self::assertSame('zypper --non-interactive install libwebkit2gtk-4_1-0', LinuxWebView::installCommand(['ID' => 'opensuse-tumbleweed', 'ID_LIKE' => 'opensuse suse']));
        self::assertSame('', LinuxWebView::installCommand(['ID' => 'gentoo']));
        self::assertTrue(LinuxWebView::isWebViewMissing(['libwebkit2gtk-4.1.so.0', 'libsoup-3.0.so.0']));
        self::assertFalse(LinuxWebView::isWebViewMissing(['libfoo.so.1']));
    }

    public function testAGlobalShortcutCallsBack(): void
    {
        $count = 0;
        $this->app->globalShortcut('CmdOrCtrl+Shift+K', function () use (&$count) {
            $count++;
        });
        self::assertSame('CmdOrCtrl+Shift+K', $this->runtime->sentOf('shortcut.register')[0]['accelerator']);
        $this->runtime->push(['type' => 'shortcut', 'accelerator' => 'CmdOrCtrl+Shift+K', 'state' => 'pressed']);
        $this->runtime->push(['type' => 'shortcut', 'accelerator' => 'CmdOrCtrl+Shift+K', 'state' => 'released']);
        $this->runtime->frame();
        self::assertSame(1, $count);
    }

    public function testAHybridGameWithoutWhatItNeedsRunsAsAPlainGame(): void
    {
        $config = new \JumPHP\Kernel\GameConfig(['ui' => 'hybrid']);
        putenv('JUMPHP_SMOKE=1');
        try {
            self::assertFalse(\JumPHP\Tauri\Hybrid::wanted($config));     // headless runs: a plain game
        } finally {
            putenv('JUMPHP_SMOKE');
        }
        self::assertFalse(\JumPHP\Tauri\Hybrid::wanted(new \JumPHP\Kernel\GameConfig([])));
    }
}
