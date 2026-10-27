<?php
declare(strict_types=1);

namespace phpcent\Integration;

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;

/** Test-only Centrifugo 3 receiver. JWTs and unrelated publications are never returned. */
final class WebSocketProbe
{
    public static function receive(string $url, string $token, array $expected, callable $publish, ?callable $ready = null): array
    {
        $loop = new Select();
        Worker::$globalEvent = $loop;
        Timer::init($loop);
        $seen = [];
        $connected = false;
        $closing = false;
        $failure = null;
        $socket = new AsyncTcpConnection($url);
        $fail = static function (\Throwable $e) use (&$failure, $loop): void { $failure = $e; $loop->stop(); };
        $socket->onWebSocketConnect = static function ($connection) use ($token): void {
            $connection->send(json_encode(['id' => 1, 'method' => 0, 'params' => ['token' => $token]], JSON_THROW_ON_ERROR));
        };
        $socket->onMessage = static function ($connection, $raw) use (&$seen, &$connected, $publish, $expected, $fail): void {
            try {
                foreach (explode("\n", trim($raw)) as $line) {
                    if ($line === '') { continue; }
                    $frame = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (($frame['id'] ?? null) === 1) {
                        if (isset($frame['error']) || empty($frame['result']['client'])) {
                            throw new \RuntimeException('Centrifugo rejected connection JWT: '.($frame['error']['code'] ?? 'missing-client'));
                        }
                        $connected = true;
                        $publish();
                        continue;
                    }
                    $push = $frame['result'] ?? [];
                    $data = $push['data']['data'] ?? null;
                    if (!is_array($data)) { continue; }
                    foreach ($expected as $name => $match) {
                        if (($push['channel'] ?? null) === $match['channel'] && ($data['test_marker'] ?? null) === $match['marker']) {
                            $seen[$name] = true;
                        }
                    }
                }
            } catch (\Throwable $e) { $fail($e); }
        };
        $socket->onError = static function ($connection, $code) use ($fail): void {
            $fail(new \RuntimeException('WebSocket transport failed: '.$code));
        };
        $socket->onClose = static function () use (&$closing, $fail): void {
            if (!$closing) { $fail(new \RuntimeException('WebSocket closed before all expected publications')); }
        };
        Timer::add(0.02, static function () use (&$seen, &$connected, $expected, $ready, $loop): void {
            if ($connected && count($seen) === count($expected) && ($ready === null || $ready())) { $loop->stop(); }
        });
        Timer::add(15, static function () use ($fail): void { $fail(new \RuntimeException('Timed out waiting for WebSocket publications')); }, [], false);
        try {
            $socket->connect();
            $loop->run();
        } finally {
            $closing = true;
            $socket->close();
            Timer::delAll();
            $loop->stop();
            Worker::$globalEvent = null;
        }
        if ($failure !== null) { throw $failure; }
        if (count($seen) !== count($expected)) { throw new \RuntimeException('Missing WebSocket publications'); }
        return ['connection_jwt_accepted' => $connected, 'received' => array_keys($seen), 'expected' => count($expected)];
    }
}
