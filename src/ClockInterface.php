<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Clock abstraction compatible with PSR-20.
 */
interface ClockInterface
{
    /**
     * Returns the current point in time.
     */
    public function now(): \DateTimeImmutable;
}
