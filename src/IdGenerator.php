<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Generates unique identifiers for bookings.
 */
interface IdGenerator
{
    /**
     * Returns the next booking identifier.
     */
    public function nextId(): string;
}
