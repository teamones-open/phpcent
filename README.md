# teamones/phpcent

Centrifugo **3.x** HTTP API client for Teamones, with PHP 8.5 compatibility and optional Workerman 5 asynchronous requests. The PHP namespace remains **`phpcent`**.

The Teamones package version is independent of upstream `centrifugal/phpcent`: **teamones/phpcent 5.x targets Centrifugo 3.x**, not the server version targeted by upstream phpcent 5.x/6.x.

```sh
composer remove centrifugal/phpcent --no-update
composer require teamones/phpcent:^5.0.1
```

PHP >= 8.1 and cURL/JSON extensions are required. Synchronous requests and JWT generation have no Workerman dependency and can be used in existing Workerman 4 or 5 services. Both phpcent packages define the same namespace and must not be installed together.

Use **5.0.1 or newer**: 5.0.0 required Workerman HTTP client 3.x unconditionally and cannot be installed alongside dependencies restricted to Workerman 4. Version 5.0.1 makes this transport optional.

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

For asynchronous requests, explicitly install the optional transport in a Workerman 5 project:

```sh
composer require workerman/http-client:^3.1
```

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
- PHP >= 8.1 is required. Workerman HTTP client >= 3.1 is only required when async mode is enabled; synchronous consumers do not need a worker upgrade.
- The Workerman HTTP client is created lazily; generating JWTs does not create an HTTP client or require a running event loop.
- PHP 8.5 no longer calls deprecated `curl_close()`. Invalid JSON is handled at the request that produced it rather than through stale global JSON error state.
- The package name remains `teamones/phpcent` and namespace remains `phpcent`.

## Tests

```sh
composer install
composer test
```

The regression tests use loopback-only fixture servers for HTTP command framing, authorization, sync/async replies, errors and JWT signing. Transport fixtures need `pcntl` / `posix` and skip when unavailable. They do not need a Centrifugo instance or real credentials. CI runs PHP 8.1–8.5; local release validation also checks publish/broadcast against Centrifugo 3.1.1 on fresh test channels.

## Real Centrifugo 3 integration test

Set PHPCENT_TEST_API_URL to the test server /api URL and PHPCENT_TEST_CONFIG to a read-only test configuration JSON path, then run composer test:integration.
The script reads api_key and token_hmac_secret_key only in memory and never prints them. It requires the development Workerman HTTP client and a live Centrifugo 3 server with presence and history enabled.

It verifies real JWT/WebSocket connection, synchronous publish, broadcast reception on two channels, asynchronous publish/callback, presence, API authentication/error propagation and history removal. Every channel and user identifier is freshly randomized; no business users are contacted. Its own message history is cleared.

Centrifugo 3 history without limit returns stream position, not message bodies. The existing history(channel) signature is preserved; the integration test uses an independent explicit-limit query to prove history removal. Successful empty command replies remain stdClass by default or [] with setUseAssoc(true).

The Teamones service-level APISIX/OAuth push regression lives in the IM repository tests/centrifugo_push_integration.php and only targets the authenticated test account.
