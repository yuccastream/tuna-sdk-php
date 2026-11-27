<?php

declare(strict_types=1);

namespace Tuna;

/**
 * A tunnel from a public tuna.am address to a local address, served by a
 * tuna-tiny process. It survives reconnects and stops after close() or a
 * permanent error.
 */
final class Tunnel
{
    /** The SDK version. */
    public const VERSION = '0.1.0';

    /** Value of TUNA_CLIENT_SUFFIX: tuna-tiny reports itself as tuna-tiny-php-sdk/<version>. */
    public const CLIENT_SUFFIX = 'php-sdk';

    private const OPTIONS = [
        'apiKey', 'protocol', 'location', 'subdomain', 'domain', 'port', 'proxyProtocol',
        'cidrAllow', 'cidrDeny', 'serverURL', 'connectTimeout', 'onEvent', 'binary', 'cacheDir',
    ];

    /** @var resource */
    private $process;
    /** @var resource|null */
    private $stdout;
    private string $buffer = '';
    private string $errorFile;
    /** @var list<Event> */
    private array $pending = [];
    private ?Event $lastFailure = null;
    private string $url = '';
    private bool $finished = false;
    private ?TunaException $failure = null;
    /** @var (callable(Event): void)|null */
    private $onEvent;

    /**
     * @param resource $process
     * @param resource $stdout
     * @param (callable(Event): void)|null $onEvent
     */
    private function __construct($process, $stdout, string $errorFile, ?callable $onEvent)
    {
        $this->process = $process;
        $this->stdout = $stdout;
        $this->errorFile = $errorFile;
        $this->onEvent = $onEvent;
    }

    /**
     * Starts a tunnel to $addr and returns once it is connected.
     *
     * $addr is a port ("8080"), "host:port", "http://host:port" or
     * "unix:///path/to.sock".
     *
     * Options:
     * - protocol: "http" (default) or "tcp";
     * - apiKey: API key with the tunnels:connect scope, defaults to TUNA_API_KEY;
     * - subdomain, domain: reserved subdomain or custom domain, HTTP only;
     * - port, proxyProtocol (1 or 2), cidrAllow, cidrDeny (lists): TCP only;
     * - location: legacy "ru" or "nl" zone of reserved names and ports;
     * - serverURL: tuna.am API address, defaults to https://api.tuna.am;
     * - connectTimeout: seconds to wait for the first connection, 0 means no limit;
     * - onEvent: callable receiving every Event, including those before forward() returns;
     * - binary: path to tuna-tiny; cacheDir: where to download it when it is not in PATH.
     *
     * @param array<string, mixed> $options
     *
     * @throws ServerException the API rejected the tunnel
     * @throws TerminatedException the server closed the tunnel
     * @throws TunaException tuna-tiny could not be started or did not connect in time
     * @throws \InvalidArgumentException an option is unknown or invalid
     */
    public static function forward(array $options, string $addr): self
    {
        [$argv, $env] = self::command($options, $addr);
        array_unshift($argv, TinyBinary::locate($options['binary'] ?? null, $options['cacheDir'] ?? null));
        $errorFile = (string) tempnam(sys_get_temp_dir(), 'tuna-tiny');
        $pipes = [];
        $process = @proc_open($argv, [0 => ['file', self::nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            @unlink($errorFile);
            throw new TunaException('tuna: cannot start ' . $argv[0] . ': ' . (error_get_last()['message'] ?? 'unknown error'));
        }
        stream_set_blocking($pipes[1], false);
        $tunnel = new self($process, $pipes[1], $errorFile, $options['onEvent'] ?? null);
        $timeout = (float) ($options['connectTimeout'] ?? 0);
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;
        while (true) {
            $e = $tunnel->read($deadline);
            if ($e === false) {
                $tunnel->close();
                $tunnel->drain();
                throw new TunaException(sprintf('tuna: tunnel did not come up in %gs', $timeout));
            }
            if ($e === null) {
                throw $tunnel->failure ?? new TunaException('tuna: tuna-tiny exited before the tunnel came up');
            }
            $tunnel->pending[] = $e;
            if ($e->type === Event::CONNECTED) {
                return $tunnel;
            }
        }
    }

    /**
     * Returns the public address, e.g. "https://name.ru.tuna.am" or "tcp://ru.tuna.am:12345".
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * Yields tunnel events until the tunnel stops, starting with the events
     * received during forward(). Ends normally after close(); throws on a
     * permanent error.
     *
     * @return \Generator<int, Event>
     *
     * @throws ServerException
     * @throws TerminatedException
     * @throws TunaException
     */
    public function events(): \Generator
    {
        while (true) {
            if ($this->pending !== []) {
                yield array_shift($this->pending);
                continue;
            }
            $e = $this->read(null);
            if ($e === null) {
                break;
            }
            if ($e !== false) {
                yield $e;
            }
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    /**
     * Blocks until the tunnel stops: returns after close(), throws on a
     * permanent error.
     *
     * @throws ServerException
     * @throws TerminatedException
     * @throws TunaException
     */
    public function wait(): void
    {
        foreach ($this->events() as $_) {
        }
    }

    /**
     * Asks tuna-tiny to close the tunnel. Safe to call from a signal handler
     * and more than once; wait() and events() return once tuna-tiny exits.
     * On Windows the process is killed without a signal.
     */
    public function close(): void
    {
        if (!$this->finished) {
            @proc_terminate($this->process);
        }
    }

    public function __destruct()
    {
        if (!$this->finished) {
            $this->close();
            $this->drain();
        }
    }

    /**
     * Builds the tuna-tiny arguments (without the binary) and environment.
     *
     * @param array<string, mixed> $options
     *
     * @return array{list<string>, array<string, string>}
     */
    public static function command(array $options, string $addr): array
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('tuna: unknown option ' . implode(', ', $unknown));
        }
        $protocol = $options['protocol'] ?? 'http';
        if ($protocol !== 'http' && $protocol !== 'tcp') {
            throw new \InvalidArgumentException("tuna: unsupported protocol \"{$protocol}\", use http or tcp");
        }
        if ($addr === '') {
            throw new \InvalidArgumentException('tuna: local address is empty');
        }
        $only = $protocol === 'http' ? ['port', 'proxyProtocol', 'cidrAllow', 'cidrDeny'] : ['subdomain', 'domain'];
        foreach ($only as $k) {
            if (!empty($options[$k])) {
                throw new \InvalidArgumentException("tuna: option {$k} is not supported for {$protocol} tunnels");
            }
        }
        $argv = [$protocol, $addr, '--log-format', 'json'];
        $flags = ['location' => '-l', 'subdomain' => '-s', 'domain' => '-d', 'port' => '-p', 'proxyProtocol' => '--proxy-protocol'];
        foreach ($flags as $k => $flag) {
            if (isset($options[$k]) && (string) $options[$k] !== '' && (string) $options[$k] !== '0') {
                array_push($argv, $flag, (string) $options[$k]);
            }
        }
        foreach (['cidrAllow' => '--cidr-allow', 'cidrDeny' => '--cidr-deny'] as $k => $flag) {
            foreach ((array) ($options[$k] ?? []) as $cidr) {
                array_push($argv, $flag, (string) $cidr);
            }
        }

        $env = array_filter(getenv(), static fn (string $k): bool => !str_starts_with(strtoupper($k), 'TUNA_'), ARRAY_FILTER_USE_KEY);
        $env['TUNA_CLIENT_SUFFIX'] = self::CLIENT_SUFFIX;
        $key = (string) ($options['apiKey'] ?? '') ?: (string) getenv('TUNA_API_KEY');
        if ($key !== '') {
            $env['TUNA_API_KEY'] = $key;
        }
        if (!empty($options['serverURL'])) {
            $env['TUNA_ADDR'] = (string) $options['serverURL'];
        }

        return [$argv, $env];
    }

    /**
     * @return Event|false|null the next event, false on deadline, null once tuna-tiny has exited
     */
    private function read(?float $deadline): Event|false|null
    {
        while (true) {
            $nl = strpos($this->buffer, "\n");
            if ($nl !== false) {
                $line = substr($this->buffer, 0, $nl);
                $this->buffer = (string) substr($this->buffer, $nl + 1);
                $data = json_decode($line, true);
                if (!is_array($data) || !is_string($data['event'] ?? null) || $data['event'] === '') {
                    continue;
                }

                return $this->handle(Event::fromArray($data));
            }
            if ($this->stdout === null) {
                $this->finish();

                return null;
            }
            $wait = 1.0;
            if ($deadline !== null) {
                $wait = min($wait, $deadline - microtime(true));
                if ($wait <= 0) {
                    return false;
                }
            }
            if (PHP_OS_FAMILY === 'Windows') {
                usleep((int) (min($wait, 0.05) * 1e6));
            } else {
                $r = [$this->stdout];
                $w = $x = null;
                if (!@stream_select($r, $w, $x, (int) $wait, (int) (fmod($wait, 1) * 1e6))) {
                    continue;
                }
            }
            $chunk = @fread($this->stdout, 65536);
            if ($chunk !== false && $chunk !== '') {
                $this->buffer .= $chunk;
            } elseif (feof($this->stdout)) {
                fclose($this->stdout);
                $this->stdout = null;
                if ($this->buffer !== '' && !str_ends_with($this->buffer, "\n")) {
                    $this->buffer .= "\n";
                }
            }
        }
    }

    private function handle(Event $e): Event
    {
        if ($e->type === Event::CONNECTED || $e->type === Event::RECONNECTED) {
            $this->url = (string) $e->url;
        }
        if ($e->type === Event::TERMINATED || $e->type === Event::ERROR) {
            $this->lastFailure = $e;
        }
        if ($this->onEvent !== null) {
            ($this->onEvent)($e);
        }

        return $e;
    }

    private function drain(): void
    {
        while ($this->read(null) !== null) {
        }
    }

    private function finish(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $code = proc_close($this->process);
        $stderr = trim((string) @file_get_contents($this->errorFile));
        @unlink($this->errorFile);
        if ($code === 0) {
            return;
        }
        $e = $this->lastFailure;
        $this->failure = match (true) {
            $code === 1 && $e?->type === Event::TERMINATED => new TerminatedException((string) $e->reason, (string) ($e->message ?? $e->reason)),
            $code === 1 && $e?->type === Event::ERROR => ServerException::fromError((string) $e->error),
            default => new TunaException("tuna: tuna-tiny exited with code {$code}" . ($stderr !== '' ? ": {$stderr}" : '')),
        };
    }

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
