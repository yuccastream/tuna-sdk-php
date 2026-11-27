<?php

declare(strict_types=1);

namespace Tuna;

/**
 * Base class of the SDK errors. A tunnel throws only permanent errors:
 * everything else is retried and reported as an Event::RECONNECTING event.
 */
class TunaException extends \RuntimeException
{
}
