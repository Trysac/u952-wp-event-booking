<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * The input is empty or holds only whitespace.
 */
final class BlankInputException extends \InvalidArgumentException
{
    /**
     * Creates the exception, with the message "input is blank" unless another one is given.
     *
     * @param string $message the message
     * @param null|\Throwable $previous the exception that caused this one
     */
    public function __construct(string $message = 'input is blank', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
