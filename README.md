# teamones/phpcent

Centrifugo **3.x** HTTP API client for Teamones, with PHP 8.5 compatibility and optional Workerman 5 asynchronous requests. The PHP namespace remains **`phpcent`**.

The Teamones package version is independent of upstream `centrifugal/phpcent`: **teamones/phpcent 5.x targets Centrifugo 3.x**, not the server version targeted by upstream phpcent 5.x/6.x.

```sh
composer remove centrifugal/phpcent --no-update
composer require teamones/phpcent:^5.0
```

PHP >= 8.1 and cURL/JSON extensions are required. Workerman HTTP client 3.x is installed for asynchronous support. Both phpcent packages define the same namespace and must not be installed together.

## Synchronous API

```php
$client = new \phpcent\Client($apiUrl, $apiKey, $jwtSecret);
$client->setConnectTimeoutOption(2)->setTimeoutOption(5);
$reply = $client->publish('channel', ['message' => 'Hello']);
$reply = $client->broadcast(['one', 'two'], ['message' => 'Hello']);
$token = $client->generateConnectionToken('user-id', time() + 300, [], ['channel']);
```

The endpoint must be the Centrifugo 3 API URL, for example `http://centrifugo:8000/api`. Requests use an `Authorization: apikey ...` header and a JSON `method` / `params` envelope. This package does not send the newer `/api/publish` protocol.

HTTP and malformed JSON failures throw exceptions. Centrifugo application errors remain in the decoded `error` field, preserving the existing client response contract. Responses are objects by default; use `setUseAssoc(true)` for arrays.

Existing `publish`, `broadcast`, `unsubscribe`, `disconnect`, `presence`, `presenceStats`, `history`, `historyRemove`, `channels`, `info`, connection JWT and private-channel JWT methods remain available. Legacy `presence_stats` / `history_remove` aliases are retained.

## Workerman asynchronous requests

Configure this inside a running Workerman 5 worker:

```php
$client->setAsync(
    true,
    function ($reply) { /* inspect the decoded result/error */ },
    function (\Throwable $error) { /* log or handle transport/JSON errors */ }
);
$client->publish('channel', ['message' => 'Hello']);
```

Async calls return `[]` immediately; decoded replies are delivered to the success callback. Non-200 HTTP status, malformed JSON and connection failures reach the error callback. Without an error callback, failures are thrown in the event loop. Use `setAsync(false)` to restore synchronous mode.

Timeout and SSL options should be configured before the first asynchronous request. SSL verification is enabled by default; `setSafety(false)` explicitly disables it. `forceIpResolveV4()` applies to the synchronous cURL transport.

## Upgrade from teamones/phpcent 4.1

- **Synchronous requests are now the default**, matching upstream client return semantics. Existing fire-and-forget integrations must explicitly call `setAsync(true, ...)`.
- PHP >= 8.1 and Workerman HTTP client >= 3.1 are required.
- The Workerman HTTP client is created lazily; generating JWTs does not create an HTTP client or require a running event loop.
- PHP 8.5 no longer calls deprecated `curl_close()`. Invalid JSON is handled at the request that produced it rather than through stale global JSON error state.
- The package name remains `teamones/phpcent` and namespace remains `phpcent`.

## Tests

```sh
composer install
composer test
```

The regression tests use loopback-only fixture servers for HTTP command framing, authorization, sync/async replies, errors and JWT signing. Transport fixtures need `pcntl` / `posix` and skip when unavailable. They do not need a Centrifugo instance or real credentials. CI runs PHP 8.1–8.5; local release validation also checks publish/broadcast against Centrifugo 3.1.1 on fresh test channels.
