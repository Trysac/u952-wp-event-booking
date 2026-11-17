<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Domain and validation exception thrown by the booking core.
 */
final class BookingException extends \InvalidArgumentException
{
    /**
     * Creates a new booking exception with a stable machine-readable code.
     *
     * @param string $errorCode stable machine-readable error code
     * @param string $message human-readable explanation (truncated to safe length)
     * @param ?\Throwable $previous optional underlying cause
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        $safeMessage = mb_substr($message, 0, 200, 'UTF-8');
        parent::__construct($safeMessage, 0, $previous);
    }
}
