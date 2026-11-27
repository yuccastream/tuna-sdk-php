<?php

declare(strict_types=1);

namespace Tuna;

/**
 * A permanent error response from the tuna.am API, such as an invalid API key.
 */
final class ServerException extends TunaException
{
    /**
     * @param string $error the error text as reported by tuna-tiny
     * @param string $serverMessage the human-readable error text from the API
     * @param string|null $requestId identifies the request for tuna.am support
     */
    public function __construct(
        string $error,
        public readonly string $serverMessage,
        public readonly ?string $requestId,
    ) {
        parent::__construct($error);
    }

    /**
     * Parses an error text of the form "tuna: <message> (request id <id>)".
     */
    public static function fromError(string $error): self
    {
        $text = str_starts_with($error, 'tuna: ') ? substr($error, 6) : $error;
        if (preg_match('/^(.*) \(request id ([^)]+)\)$/s', $text, $m) === 1) {
            return new self($error, $m[1], $m[2]);
        }

        return new self($error, $text, null);
    }
}
