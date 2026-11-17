<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Internal value object returned by a BookingStore when canceling a booking and promoting waitlisted bookings.
 */
final readonly class StoreCancelOutcome
{
    /**
     * Creates a store cancellation outcome.
     *
     * @param Booking $canceled the booking with status updated to canceled
     * @param Event $event the associated event
     * @param Attendee $attendee the attendee of the canceled booking
     * @param list<array{booking: Booking, attendee: Attendee}> $promoted waitlisted bookings promoted to confirmed
     */
    public function __construct(
        public Booking $canceled,
        public Event $event,
        public Attendee $attendee,
        public array $promoted,
    ) {}
}
