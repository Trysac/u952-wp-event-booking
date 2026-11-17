<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Deterministic clock controlled explicitly by caller or tests.
 */
final class FrozenClock implements ClockInterface
{
    /**
     * Creates a frozen clock set to the given instant.
     *
     * @param \DateTimeImmutable $current initial timestamp
     */
    public function __construct(
        private \DateTimeImmutable $current = new \DateTimeImmutable('2025-01-01T12:00:00Z'),
    ) {}

    /**
     * Returns the currently configured timestamp.
     */
    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    /**
     * Advances or sets the clock to a new instant.
     *
     * @param \DateTimeImmutable $next new timestamp
     */
    public function set(\DateTimeImmutable $next): void
    {
        $this->current = $next;
    }
}
