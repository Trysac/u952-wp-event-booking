<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Immutable representation of a bookable event.
 */
final readonly class Event
{
    /**
     * Creates an event definition.
     *
     * @param string $id unique event identifier
     * @param string $title event display title
     * @param \DateTimeImmutable $startsAt timestamp when the event begins and booking closes
     * @param int $capacity maximum confirmed seats allowed
     * @param int $waitlistCapacity maximum waitlisted seats or bookings allowed
     *
     * @throws BookingException when identifiers, title, or capacities are invalid
     */
    public function __construct(
        public string $id,
        public string $title,
        public \DateTimeImmutable $startsAt,
        public int $capacity,
        public int $waitlistCapacity = 100,
    ) {
        if ('' === trim($this->id) || mb_strlen($this->id, 'UTF-8') > 200) {
            throw new BookingException('unknown_event', 'Event id must be between 1 and 200 characters.');
        }
        if ('' === trim($this->title) || mb_strlen($this->title, 'UTF-8') > 200) {
            throw new BookingException('invalid_name', 'Event title must be between 1 and 200 characters.');
        }
        if (1 === preg_match('/[\r\n]/', $this->title)) {
            throw new BookingException('header_injection', 'Event title must not contain CR or LF characters.');
        }
        if ($this->capacity < 0 || $this->waitlistCapacity < 0) {
            throw new BookingException('invalid_seats', 'Event capacity and waitlist capacity must be non-negative.');
        }
    }
}
