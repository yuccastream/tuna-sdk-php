<?php

declare(strict_types=1);

namespace Tuna;

/**
 * A change in the tunnel state.
 */
final class Event
{
    /** Sent once, when the tunnel comes up for the first time. */
    public const CONNECTED = 'connected';
    /** Sent when the tunnel comes up again after a failure. */
    public const RECONNECTED = 'reconnected';
    /** Sent after a failed attempt or a lost connection, before the next attempt. */
    public const RECONNECTING = 'reconnecting';
    /** Carries a server message for the user. */
    public const BANNER = 'banner';
    /** Warns that the session time limit is near. */
    public const SESSION_EXPIRING = 'session_expiring';
    /** Sent when the server closes the tunnel for good. */
    public const TERMINATED = 'terminated';
    /** Reports a failure to reach the local address. */
    public const LOCAL_ERROR = 'local_error';
    /** Reports the permanent error the tunnel stopped with. */
    public const ERROR = 'error';

    /**
     * @param string $type one of the constants above
     * @param string $time RFC 3339 time of the event
     * @param string|null $url public address, set for CONNECTED and RECONNECTED
     * @param string|null $message human-readable text, set for BANNER and TERMINATED
     * @param string|null $reason machine-readable cause, set for TERMINATED
     * @param string|null $error error text, set for RECONNECTING, LOCAL_ERROR and ERROR
     * @param float|null $retryIn seconds before the next attempt, set for RECONNECTING
     * @param float|null $expiresIn seconds left, set for SESSION_EXPIRING
     */
    public function __construct(
        public readonly string $type,
        public readonly string $time = '',
        public readonly ?string $url = null,
        public readonly ?string $message = null,
        public readonly ?string $reason = null,
        public readonly ?string $error = null,
        public readonly ?float $retryIn = null,
        public readonly ?float $expiresIn = null,
    ) {
    }

    /**
     * Builds an event from one decoded line of tuna-tiny JSON output.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $str = static fn (string $k): ?string => isset($data[$k]) && is_string($data[$k]) && $data[$k] !== '' ? $data[$k] : null;
        $num = static fn (string $k): ?float => isset($data[$k]) && is_numeric($data[$k]) ? (float) $data[$k] : null;

        return new self(
            (string) ($data['event'] ?? ''),
            (string) ($data['time'] ?? ''),
            $str('url'),
            $str('message'),
            $str('reason'),
            $str('error'),
            $num('retry_in'),
            $num('expires_in'),
        );
    }

    /**
     * Returns the event in the tuna-tiny JSON format, without empty fields.
     *
     * @return array<string, string|float>
     */
    public function toArray(): array
    {
        return array_filter([
            'time' => $this->time,
            'event' => $this->type,
            'url' => $this->url,
            'message' => $this->message,
            'reason' => $this->reason,
            'error' => $this->error,
            'retry_in' => $this->retryIn,
            'expires_in' => $this->expiresIn,
        ], static fn ($v): bool => $v !== null && $v !== '' && $v !== 0.0);
    }

    /**
     * Reports whether a RECONNECTING event was caused by the node no longer
     * answering keepalive requests.
     */
    public function isKeepAliveTimeout(): bool
    {
        return $this->error !== null && str_contains($this->error, 'keepalive timeout');
    }
}
