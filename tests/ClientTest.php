<?php
use phpcent\Client;
use PHPUnit\Framework\TestCase;
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;

final class ClientTest extends TestCase
{
    private array $children = [];

    protected function tearDown(): void
    {
        foreach ($this->children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
        }
        if (Worker::$globalEvent !== null) {
            Timer::delAll();
            Worker::$globalEvent->stop();
            Worker::$globalEvent = null;
        }
    }

    // One-request loopback fixture exercises real cURL and Workerman I/O.
    private function server(?string $response = null, int $status = 200): string
    {
        if (!function_exists('pcntl_fork')) $this->markTestSkipped('Transport tests require pcntl');
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Unable to fork fixture');
        if ($pid === 0) {
            $socket = stream_socket_accept($server, 5);
            if (!$socket) exit(2);
            stream_set_timeout($socket, 5);
            $requestLine = trim(fgets($socket));
            $headers = [];
            while (($line = fgets($socket)) !== false && trim($line) !== '') {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower($key)] = trim($value);
            }
            $body = '';
            $length = (int)($headers['content-length'] ?? 0);
            while (strlen($body) < $length && !feof($socket)) $body .= fread($socket, $length - strlen($body));
            $payload = $response ?? json_encode(['result' => ['request_line' => $requestLine, 'headers' => $headers, 'body' => $body]]);
            fwrite($socket, "HTTP/1.1 $status Fixture\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\nConnection: close\r\n\r\n".$payload);
            fclose($socket);
            fclose($server);
            exit(0);
        }
        $this->children[] = $pid;
        fclose($server);
        return 'http://'.$address.'/api';
    }

    public static function commands(): array
    {
        return [
            ['publish', ['room', ['text' => 'hello']], 'publish', ['channel' => 'room', 'data' => ['text' => 'hello']]],
            ['broadcast', [['one', 'two'], ['text' => 'hello']], 'broadcast', ['channels' => ['one', 'two'], 'data' => ['text' => 'hello']]],
            ['unsubscribe', ['room', 'user'], 'unsubscribe', ['channel' => 'room', 'user' => 'user']],
            ['disconnect', ['user'], 'disconnect', ['user' => 'user']],
            ['presence', ['room'], 'presence', ['channel' => 'room']],
            ['presenceStats', ['room'], 'presence_stats', ['channel' => 'room']],
            ['presence_stats', ['room'], 'presence_stats', ['channel' => 'room']],
            ['history', ['room'], 'history', ['channel' => 'room']],
            ['historyRemove', ['room'], 'history_remove', ['channel' => 'room']],
            ['history_remove', ['room'], 'history_remove', ['channel' => 'room']],
            ['channels', [], 'channels', []], ['info', [], 'info', []],
        ];
    }

    /** @dataProvider commands */
    public function testCentrifugo3Protocol(string $call, array $args, string $method, array $params): void
    {
        $client = (new Client($this->server(), 'test:key'))->setUseAssoc(true)->setTimeoutOption(2);
        $reply = $client->$call(...$args);
        self::assertSame('POST /api HTTP/1.1', $reply['result']['request_line']);
        self::assertSame('apikey test:key', $reply['result']['headers']['authorization']);
        self::assertArrayNotHasKey('x-api-key', $reply['result']['headers']);
        $command = json_decode($reply['result']['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($method, $command['method']);
        self::assertSame($params, $command['params']);
        self::assertIsObject(json_decode($reply['result']['body'])->params);
    }

    public function testApiErrorRemainsAvailable(): void
    {
        $client = new Client($this->server('{"error":{"code":102,"message":"unknown channel"}}'));
        self::assertSame(102, $client->info()->error->code);
    }

    public function testHttpFailureIsNotSuccess(): void
    {
        $client = new Client($this->server('{}', 404));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('404');
        $client->info();
    }

    public function testMalformedResponseIsRejected(): void
    {
        $client = new Client($this->server('not json'));
        $this->expectException(JsonException::class);
        $client->info();
    }

    public function testMalformedRequestIsRejectedBeforeNetworkIo(): void
    {
        $client = new Client('http://127.0.0.1:1/api');
        $this->expectException(JsonException::class);
        $client->publish('room', ['text' => "\xB1\x31"]);
    }

    public function testJwtContracts(): void
    {
        $client = new Client('', '', 'synthetic-secret');
        $token = $client->generateConnectionToken(123, 1900000000, ['role' => 'test'], ['room']);
        [$header, $payload, $signature] = explode('.', $token);
        $decode = static fn($value) => json_decode(base64_decode(strtr($value, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['typ' => 'JWT', 'alg' => 'HS256'], $decode($header));
        self::assertSame(['sub' => '123', 'info' => ['role' => 'test'], 'channels' => ['room'], 'exp' => 1900000000], $decode($payload));
        self::assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", 'synthetic-secret', true)), '+/', '-_'), '='), $signature);
        $private = explode('.', $client->generatePrivateChannelToken('connection', 'room', 1900000000));
        self::assertSame(['channel' => 'room', 'client' => 'connection', 'exp' => 1900000000], $decode($private[1]));
        self::assertNull((new ReflectionProperty(Client::class, 'workermanHttpClient'))->getValue($client));
    }

    public static function asyncResponses(): array
    {
        return [[null, 200, null], ['broken', 200, JsonException::class], ['{}', 503, RuntimeException::class]];
    }

    /** @dataProvider asyncResponses */
    public function testWorkerman5Async(?string $response, int $status, ?string $expectedError): void
    {
        $url = $this->server($response, $status);
        $loop = new Select();
        Worker::$globalEvent = $loop;
        Timer::init($loop);
        $reply = null;
        $failure = null;
        $client = (new Client($url, 'async:key'))->setUseAssoc(true)->setTimeoutOption(2);
        $client->setAsync(true,
            static function ($data) use (&$reply, $loop) {$reply = $data; $loop->stop();},
            static function ($error) use (&$failure, $loop) {$failure = $error; $loop->stop();}
        );
        self::assertSame([], $client->publish('room', ['text' => 'async']));
        $loop->delay(3, static function () use ($loop) {$loop->stop();});
        $loop->run();
        if ($expectedError !== null) {
            self::assertInstanceOf($expectedError, $failure);
            self::assertNull($reply);
        } else {
            self::assertNull($failure);
            self::assertSame('POST /api HTTP/1.1', $reply['result']['request_line']);
            self::assertSame('apikey async:key', $reply['result']['headers']['authorization']);
            self::assertSame('publish', json_decode($reply['result']['body'], true)['method']);
        }
    }
}
