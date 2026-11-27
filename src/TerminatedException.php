<?php

declare(strict_types=1);

namespace Tuna;

/**
 * The server closed the tunnel for good.
 */
final class TerminatedException extends TunaException
{
    /**
     * @param string $reason machine-readable cause, e.g. "deleted" or "session_limit"
     * @param string $text human-readable text
     */
    public function __construct(public readonly string $reason, string $text)
    {
        parent::__construct('tuna: ' . $text);
    }
}
