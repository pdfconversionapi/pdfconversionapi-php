<?php

declare(strict_types=1);

namespace PdfConversionApi\Internal;

/**
 * An attempt that failed below HTTP.
 *
 * @internal
 */
final class TransportFailure extends \RuntimeException
{
    /** Nothing came back: DNS failure, refused or reset connection, connect timeout. Retried. */
    public const NO_RESPONSE = 'no_response';
    /** The client's own timeout ran out once the connection was open. Never retried. */
    public const TIMEOUT = 'timeout';
    /** The connection broke after the status line arrived. Never retried. */
    public const BROKEN_RESPONSE = 'broken_response';

    /**
     * @param self::NO_RESPONSE|self::TIMEOUT|self::BROKEN_RESPONSE $kind
     */
    public function __construct(string $message, public readonly string $kind, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
