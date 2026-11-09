<?php

namespace JumPHP\Tests\Native;

use JumPHP\Bridge\JsBridge;
use JumPHP\Native\BrowserProvider;
use PHPUnit\Framework\TestCase;

final class BrowserFilePickerTest extends TestCase
{
    private function bridge(): JsBridge
    {
        return new class extends JsBridge {
            /** @var list<array{string, array}> */
            public array $sent = [];

            public function __construct()
            {
            }

            public function send(string $type, array $data = []): bool
            {
                $this->sent[] = [$type, $data];
                return true;
            }
        };
    }

    public function testTheChosenFileComesBackAsAPath(): void
    {
        $bridge = $this->bridge();
        $provider = new BrowserProvider($bridge);
        self::assertTrue($provider->supports('open'));
        $task = $provider->open(['filters' => ['Keys' => ['pem', 'key']]]);
        self::assertSame('filepick', $bridge->sent[0][0]);
        self::assertSame('.pem,.key', $bridge->sent[0][1]['accept']);
        self::assertFalse($task->isDone());
        $bridge->emit('filepick', [['id' => $bridge->sent[0][1]['id'], 'paths' => ['/tmp/jumphp-picked/id_ed25519']]]);
        self::assertSame('/tmp/jumphp-picked/id_ed25519', $task->result());
    }

    public function testCancellingGivesNullAndMultipleGivesAList(): void
    {
        $bridge = $this->bridge();
        $provider = new BrowserProvider($bridge);
        $cancelled = $provider->open([]);
        $bridge->emit('filepick', [['id' => $bridge->sent[0][1]['id'], 'paths' => null]]);
        self::assertNull($cancelled->result());
        $many = $provider->open(['multiple' => true]);
        $bridge->emit('filepick', [['id' => $bridge->sent[1][1]['id'], 'paths' => ['/tmp/jumphp-picked/a', '/tmp/jumphp-picked/b']]]);
        self::assertSame(['/tmp/jumphp-picked/a', '/tmp/jumphp-picked/b'], $many->result());
    }
}
