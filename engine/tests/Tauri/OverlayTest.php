<?php

namespace JumPHP\Tests\Tauri;

use JumPHP\Tauri\Overlay;
use PHPUnit\Framework\TestCase;

final class OverlayTest extends TestCase
{
    private string $dir;
    private string $root;
    private string $data;

    protected function setUp(): void
    {
        putenv('JUMPHP_NO_OVERLAY');
        $this->dir = sys_get_temp_dir() . '/overlay-test-' . bin2hex(random_bytes(4));
        $this->root = "$this->dir/cache/ide-abc";
        $this->data = "$this->dir/data";
        mkdir("$this->root/src", 0777, true);
        mkdir("$this->root/config", 0777, true);
        mkdir("$this->root/vendor/jumphp/ide/ui", 0777, true);
        file_put_contents("$this->root/src/Main.php", 'base');
        file_put_contents("$this->root/src/Old.php", 'old');
        file_put_contents("$this->root/config/game.yaml", "game:\n  version: 0.1.0\n  overlay_compat: 1\n");
        file_put_contents("$this->root/vendor/jumphp/ide/ui/app.js", 'base-js');
        mkdir($this->data, 0777, true);
    }

    protected function tearDown(): void
    {
        $rm = static function (string $d) use (&$rm): void {
            foreach (scandir($d) ?: [] as $n) {
                if ($n !== '.' && $n !== '..') {
                    is_dir("$d/$n") && !is_link("$d/$n") ? $rm("$d/$n") : @unlink("$d/$n");
                }
            }
            @rmdir($d);
        };
        $rm($this->dir);
    }

    /** @param array<string, string> $files */
    private function archive(string $version, array $files, int $compat = 1, array $delete = [], bool $gz = true): array
    {
        $tar = "$this->dir/o-$version.tar";
        @unlink($tar);
        @unlink("$tar.gz");
        $phar = new \PharData($tar);
        $phar->addFromString('manifest.json', json_encode(['version' => $version, 'compat' => $compat, 'delete' => $delete,
            'files' => array_map(fn ($c) => hash('sha256', $c), $files)]));
        foreach ($files as $path => $content) {
            try {
                $phar->addFromString("files/$path", $content);
            } catch (\BadMethodCallException) {
                // a hostile path (../, //): PharData refuses to write it, the manifest still names it, as a forged archive would
            }
        }
        if ($gz) {
            $phar->compress(\Phar::GZ);
            $tar .= '.gz';
        }
        unset($phar);
        $bytes = (string)file_get_contents($tar);
        return [$bytes, ['version' => $version, 'compat' => $compat, 'sha256' => hash('sha256', $bytes)]];
    }

    public function testWithoutAnOverlayTheAppRunsFromItsOwnFiles(): void
    {
        self::assertSame($this->root, Overlay::resolve($this->root, $this->data));
    }

    public function testAnOverlayOlderThanTheInstalledExecutableIsDroppedAndNeverHidesIt(): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'new']);
        Overlay::install($this->data, $bytes, $expected, 1);
        file_put_contents("$this->root/config/game.yaml", "game:\n  version: 0.1.2\n  overlay_compat: 1\n");   // a newer executable was installed
        self::assertSame($this->root, Overlay::resolve($this->root, $this->data));
        self::assertNull(Overlay::running($this->data));
    }

    public function testAnOverlayIsLaidOverACopyAndTheExecutablesOwnFilesStayUntouched(): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'new', 'src/New.php' => 'added'], delete: ['src/Old.php']);
        Overlay::install($this->data, $bytes, $expected, 1);
        $run = Overlay::resolve($this->root, $this->data);
        self::assertSame("$this->root-ov-0.1.1", $run);
        self::assertSame('new', file_get_contents("$run/src/Main.php"));
        self::assertSame('added', file_get_contents("$run/src/New.php"));
        self::assertFileDoesNotExist("$run/src/Old.php");
        self::assertSame('base-js', file_get_contents("$run/vendor/jumphp/ide/ui/app.js"), 'what the overlay does not hold comes from the executable');
        self::assertSame('base', file_get_contents("$this->root/src/Main.php"), 'the unpacked app is never written through its hard links');
        self::assertFileExists("$this->root/src/Old.php");
    }

    public function testAnOverlayThatDiedOfAPhpFatalErrorIsDroppedAtTheNextStartAndNeverTriedAgain(): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'broken']);
        Overlay::install($this->data, $bytes, $expected, 1);
        self::assertSame("$this->root-ov-0.1.1", Overlay::resolve($this->root, $this->data));
        // what the shutdown function writes when PHP ends on a fatal error while the overlay is pending
        $state = Overlay::state($this->data);
        file_put_contents(Overlay::dir($this->data) . '/state.json', json_encode(['crashed' => true] + $state));
        self::assertSame($this->root, Overlay::resolve($this->root, $this->data));
        self::assertTrue(Overlay::isBad($this->data, '0.1.1'));
        self::assertSame($this->root, Overlay::resolve($this->root, $this->data));
    }

    public function testAStartThatWasOnlyNotConfirmedGetsAnotherTryAndIsDroppedAfterThree(): void
    {
        // a frozen window killed by the user is not the overlay's proof of guilt (the freeze also happens without one)
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'x']);
        Overlay::install($this->data, $bytes, $expected, 1);
        $run = "$this->root-ov-0.1.1";
        self::assertSame($run, Overlay::resolve($this->root, $this->data));   // try 1, killed
        self::assertSame($run, Overlay::resolve($this->root, $this->data));   // try 2, killed
        self::assertSame($run, Overlay::resolve($this->root, $this->data));   // try 3, killed
        self::assertFalse(Overlay::isBad($this->data, '0.1.1'));
        self::assertSame($this->root, Overlay::resolve($this->root, $this->data));   // never got there three times in a row
        self::assertTrue(Overlay::isBad($this->data, '0.1.1'));
    }

    public function testConfirmingStartsTheCountAgain(): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'x']);
        Overlay::install($this->data, $bytes, $expected, 1);
        Overlay::resolve($this->root, $this->data);
        Overlay::resolve($this->root, $this->data);          // two starts not confirmed
        Overlay::confirm($this->data);
        self::assertSame(0, Overlay::state($this->data)['tries'] === 0 ? 0 : 1);
        for ($i = 0; $i < 3; $i++) {
            self::assertSame("$this->root-ov-0.1.1", Overlay::resolve($this->root, $this->data));
            Overlay::confirm($this->data);
        }
        self::assertFalse(Overlay::isBad($this->data, '0.1.1'));
    }

    public function testAConfirmedOverlayKeepsRunning(): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'good']);
        Overlay::install($this->data, $bytes, $expected, 1);
        Overlay::resolve($this->root, $this->data);
        Overlay::confirm($this->data);
        self::assertSame('0.1.1', Overlay::running($this->data));
        self::assertSame("$this->root-ov-0.1.1", Overlay::resolve($this->root, $this->data));
    }

    public function testAnOverlayForAnotherExecutableIsRefusedAndIgnored(): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'x'], compat: 2);
        $this->expectExceptionMessage('another executable');
        Overlay::install($this->data, $bytes, $expected, 1);
    }

    public function testAnOverlayWhoseCompatChangedSinceIsIgnoredAtStart(): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'x']);
        Overlay::install($this->data, $bytes, $expected, 1);
        file_put_contents("$this->root/config/game.yaml", "game:\n  overlay_compat: 2\n");     // a newer executable
        self::assertSame($this->root, Overlay::resolve($this->root, $this->data));
    }

    public function testADownloadThatIsNotWhatWasSignedIsRefused(): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'x']);
        $expected['sha256'] = str_repeat('0', 64);
        $this->expectExceptionMessage('SHA-256');
        Overlay::install($this->data, $bytes, $expected, 1);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenPaths')]
    public function testAnOverlayCannotWriteOutsideItsPlaces(string $path): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', [$path => 'x']);
        $this->expectExceptionMessage('outside');
        Overlay::install($this->data, $bytes, $expected, 1);
    }

    public static function forbiddenPaths(): array
    {
        return [['vendor/jumphp/engine/src/Engine.php'], ['vendor/composer/autoload_real.php'], ['src/../../evil.php'], ['/etc/passwd'], ['app.php']];
    }

    public function testAFileThatChangedInsideTheArchiveIsRefused(): void
    {
        $tar = "$this->dir/bad.tar";
        $phar = new \PharData($tar);
        $phar->addFromString('manifest.json', json_encode(['version' => '0.1.1', 'compat' => 1, 'files' => ['src/Main.php' => hash('sha256', 'good')]]));
        $phar->addFromString('files/src/Main.php', 'tampered');
        unset($phar);
        $bytes = (string)file_get_contents($tar);
        $this->expectExceptionMessage('does not match its SHA-256');
        Overlay::install($this->data, $bytes, ['version' => '0.1.1', 'compat' => 1, 'sha256' => hash('sha256', $bytes)], 1);
    }

    public function testNoOverlayEnvironmentVariableStartsFromTheExecutablesFiles(): void
    {
        [$bytes, $expected] = $this->archive('0.1.1', ['src/Main.php' => 'x']);
        Overlay::install($this->data, $bytes, $expected, 1);
        putenv('JUMPHP_NO_OVERLAY=1');
        try {
            self::assertSame($this->root, Overlay::resolve($this->root, $this->data));
            self::assertFalse(Overlay::state($this->data)['pending']);
        } finally {
            putenv('JUMPHP_NO_OVERLAY');
        }
    }

    public function testOldOverlaysAreRemovedKeepingTheActiveAndTheOneBefore(): void
    {
        foreach (['0.1.1', '0.1.2', '0.1.3'] as $v) {
            [$bytes, $expected] = $this->archive($v, ['src/Main.php' => $v]);
            Overlay::install($this->data, $bytes, $expected, 1);
            Overlay::resolve($this->root, $this->data);
            Overlay::confirm($this->data);
        }
        self::assertDirectoryDoesNotExist(Overlay::dir($this->data) . '/0.1.1');
        self::assertDirectoryExists(Overlay::dir($this->data) . '/0.1.2');
        self::assertDirectoryExists(Overlay::dir($this->data) . '/0.1.3');
        self::assertDirectoryDoesNotExist("$this->root-ov-0.1.2");
    }
}
