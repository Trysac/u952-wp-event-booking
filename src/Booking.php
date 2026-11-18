<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Immutable record of an event booking.
 */
final readonly class Booking
{
    /**
     * Confirmed booking status.
     */
    public const string STATUS_CONFIRMED = 'confirmed';

    /**
     * Waitlisted booking status.
     */
    public const string STATUS_WAITLISTED = 'waitlisted';

    /**
     * Canceled booking status.
     */
    public const string STATUS_CANCELED = 'canceled';

    /**
     * Creates an immutable booking record.
     *
     * @param string $id unique booking identifier
     * @param string $eventId associated event identifier
     * @param int $seats number of seats requested
     * @param string $status confirmed|waitlisted|canceled
     * @param \DateTimeImmutable $createdAt booking creation timestamp
     */
    public function __construct(
        public string $id,
        public string $eventId,
        public int $seats,
        public string $status,
        public \DateTimeImmutable $createdAt,
    ) {}
}
