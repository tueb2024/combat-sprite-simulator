<?php

namespace JumPHP\Tests\Http;

use JumPHP\Async\Task;
use JumPHP\Bridge\JsBridge;
use JumPHP\Http\Http;
use JumPHP\Http\HttpError;
use JumPHP\Http\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Desktop backends (curl_multi, non-blocking sockets) against a local php -S server. */
final class HttpTest extends TestCase
{
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $port = random_int(20000, 40000);
        self::$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", dirname(__DIR__) . '/fixtures/http-router.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::$base = "http://127.0.0.1:$port";
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === false; $i++) {
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public static function backends(): array
    {
        return array_filter(['curl' => [true], 'sockets' => [false]], fn (array $b) => !$b[0] || extension_loaded('curl'));
    }

    private function http(bool $curl): Http
    {
        $http = new Http(new JsBridge());
        $http->useCurl = $curl;
        return $http;
    }

    private function wait(Http $http, Task $task, float $seconds = 5): void
    {
        $end = microtime(true) + $seconds;
        while (!$task->isDone() && microtime(true) < $end) {
            $http->poll();
            usleep(2000);   // a frame
        }
    }

    #[DataProvider('backends')]
    public function testGetJson(bool $curl): void
    {
        $http = $this->http($curl);
        $seen = null;
        $task = $http->get(self::$base . '/json')->then(function (HttpResponse $r) use (&$seen) { $seen = $r; });
        self::assertFalse($task->isDone(), 'never done at once');
        $this->wait($http, $task);
        self::assertInstanceOf(HttpResponse::class, $seen);
        self::assertSame(200, $seen->status);
        self::assertTrue($seen->ok());
        self::assertSame('yes', $seen->header('X-Test'));
        self::assertSame('GET', $seen->json()['method']);
        self::assertSame(Http::USER_AGENT, $seen->json()['ua']);
        self::assertSame(0, $http->pending());
    }

    #[DataProvider('backends')]
    public function testPostArrayIsJson(bool $curl): void
    {
        $http = $this->http($curl);
        $task = $http->post(self::$base . '/json', ['score' => 1200]);
        $this->wait($http, $task);
        $data = $task->result()->json();
        self::assertSame('POST', $data['method']);
        self::assertSame('{"score":1200}', $data['body']);
        self::assertSame('application/json', $data['type']);
    }

    #[DataProvider('backends')]
    public function testStatusRedirectAndBigBody(bool $curl): void
    {
        $http = $this->http($curl);
        $missing = $http->get(self::$base . '/nothing');
        $redirect = $http->get(self::$base . '/redirect');
        $big = $http->get(self::$base . '/big');
        foreach ([$missing, $redirect, $big] as $task) {
            $this->wait($http, $task);
        }
        self::assertSame(404, $missing->result()->status);
        self::assertFalse($missing->result()->ok());
        self::assertSame('GET', $redirect->result()->json()['method']);
        self::assertSame(500000, strlen($big->result()->body));
    }

    #[DataProvider('backends')]
    public function testErrors(bool $curl): void
    {
        $http = $this->http($curl);
        $refused = $http->get('http://127.0.0.1:1/');
        $slow = $http->request('GET', self::$base . '/slow', null, [], 0.5);
        $error = null;
        $slow->then(fn () => null, function (\Throwable $e) use (&$error) { $error = $e; });
        $this->wait($http, $refused);
        $this->wait($http, $slow, 3);
        self::assertInstanceOf(HttpError::class, $refused->error());
        self::assertInstanceOf(HttpError::class, $error);
        self::assertStringContainsStringIgnoringCase('time', $error->getMessage());
    }

    public function testBadUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->http(false)->get('ftp://example.org/');
    }
}
