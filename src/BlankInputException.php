<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Exception thrown when text input is blank.
 */
final class BlankInputException extends \InvalidArgumentException
{
    /**
     * Creates a blank input exception.
     *
     * @param string $message error explanation
     * @param ?\Throwable $previous underlying cause
     */
    public function __construct(string $message = 'input is blank', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
