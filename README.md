# tuna.am SDK for PHP

Expose a local HTTP or TCP service at a public [tuna.am](https://tuna.am)
address from PHP code: receive webhooks in development, share a local site,
reach a local database from CI.

The package runs [tuna-tiny](https://tuna.am), a small tunnel client, as a
child process and reports what it does. It needs `proc_open`, so it does not
work on shared hosting where `proc_open` is disabled.

## Install

```bash
composer require yuccastream/tuna-sdk-php
```

PHP 8.1 or newer. No PHP dependencies; `ext-pcntl` is recommended for CLI
scripts so that SIGTERM and Ctrl+C close the tunnel cleanly.

tuna-tiny is taken from, in order:

1. the `binary` option;
2. `tuna-tiny` in `PATH`;
3. a download of the tuna-tiny release this package version is pinned to,
   from `releases.tuna.am`, verified against the SHA-256 checksums shipped in
   the package and cached in `$XDG_CACHE_HOME/tuna-sdk-php` (or
   `~/.cache/tuna-sdk-php`, `%LOCALAPPDATA%\tuna-sdk-php`). Set `cacheDir` to
   change it.

Prebuilt tuna-tiny exists for Linux, macOS, Windows, FreeBSD and OpenBSD on
amd64 and arm64.

## Usage

Create an API key with the `tunnels:connect` scope in the tuna.am dashboard
and put it into `TUNA_API_KEY`.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Tuna\Event;
use Tuna\Tunnel;

$tunnel = Tunnel::forward([
    'protocol' => 'http',
    'onEvent' => function (Event $e): void {
        if ($e->type === Event::RECONNECTING) {
            fwrite(STDERR, "connection lost: {$e->error}, retry in {$e->retryIn}s\n");
        }
    },
], '8080');

echo "Forwarding {$tunnel->url()} -> localhost:8080\n";

pcntl_async_signals(true);
pcntl_signal(SIGINT, fn () => $tunnel->close());

$tunnel->wait();
```

`forward()` returns once the tunnel is up. `wait()` blocks until it stops:
it returns after `close()` and throws on a permanent error. Instead of
`wait()` you can iterate over events:

```php
foreach ($tunnel->events() as $event) {
    echo $event->type, ' ', $event->url ?? $event->error ?? '', "\n";
}
```

A TCP tunnel to a local PostgreSQL, open only to one network:

```php
$tunnel = Tunnel::forward([
    'protocol' => 'tcp',
    'cidrAllow' => ['203.0.113.0/24'],
], '127.0.0.1:5432');
echo $tunnel->url(), "\n";
```

`examples/runner.php` is a complete command-line program built on the SDK.

### Options

| Option | Meaning |
|---|---|
| `protocol` | `http` (default) or `tcp` |
| `apiKey` | API key with the `tunnels:connect` scope, defaults to `TUNA_API_KEY`; passed to tuna-tiny through the environment, never in the command line |
| `subdomain`, `domain` | reserved subdomain or custom domain, HTTP only |
| `port` | reserved TCP port or its alias, TCP only |
| `proxyProtocol` | PROXY protocol header version (1 or 2), TCP only |
| `cidrAllow`, `cidrDeny` | lists of client networks allowed or rejected, TCP only |
| `location` | only for names and ports reserved in the legacy `ru` or `nl` zones |
| `serverURL` | tuna.am API address, defaults to `https://api.tuna.am` |
| `connectTimeout` | seconds to wait for the first connection, `0` means no limit |
| `onEvent` | callable receiving every `Tuna\Event` |
| `binary`, `cacheDir` | tuna-tiny path and download cache, see above |

The local address is a port (`8080`), `host:port`, `http://host:port` or
`unix:///path/to.sock`.

### Events

`Tuna\Event` has `type` and, depending on it, `url`, `message`, `reason`,
`error`, `retryIn` and `expiresIn` (seconds):

| `type` | When |
|---|---|
| `connected` | the tunnel is up for the first time, `url` is set |
| `reconnecting` | an attempt failed or the connection was lost, `error` and `retryIn` are set; `isKeepAliveTimeout()` tells a silent connection loss |
| `reconnected` | the tunnel is up again, `url` is set |
| `banner` | a server message for the user |
| `session_expiring` | the session time limit is near, `expiresIn` is set |
| `terminated` | the server closed the tunnel for good, `reason` and `message` are set |
| `local_error` | the local address could not be reached |
| `error` | the permanent error the tunnel stopped with |

Events arrive while `forward()`, `wait()` or `events()` runs: PHP code is
synchronous, so call `wait()` or iterate `events()` to keep the tunnel
reporting.

### Errors

Network failures are retried for as long as it takes. A tunnel stops with an
exception only when retrying is pointless:

- `Tuna\ServerException` — the API rejected the tunnel, e.g. an invalid key
  or an unpaid plan; `serverMessage` and `requestId` (for tuna.am support);
- `Tuna\TerminatedException` — the server closed the tunnel, `reason` is
  e.g. `deleted` or `session_limit`;
- `Tuna\TunaException` — the base class; also thrown when tuna-tiny cannot be
  found or started, or `connectTimeout` expires.

## Notes

- `client_name` reported to tuna.am is `tuna-tiny-php-sdk/<tuna-tiny version>`.
- On Windows `close()` kills tuna-tiny without a signal: the tunnel closes by
  a dropped connection rather than cleanly.
- If the PHP process is killed with SIGKILL, tuna-tiny keeps running.

## License

MIT, see [LICENSE](LICENSE).
