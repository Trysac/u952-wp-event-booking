<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Deterministic sequential identifier generator producing 'bk_000001', 'bk_000002', etc.
 */
final class SequentialIds implements IdGenerator
{
    /**
     * Creates a sequential ID generator starting before the given sequence number.
     *
     * @param int $counter initial counter value (0 produces bk_000001 first)
     * @param string $prefix identifier prefix
     */
    public function __construct(
        private int $counter = 0,
        private readonly string $prefix = 'bk_',
    ) {}

    /**
     * Generates the next formatted identifier.
     */
    public function nextId(): string
    {
        ++$this->counter;

        return sprintf('%s%06d', $this->prefix, $this->counter);
    }
}
