<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Internal value object returned by a BookingStore when creating or deduplicating a booking.
 */
final readonly class StoreBookOutcome
{
    /**
     * Creates a store booking outcome.
     *
     * @param Booking $booking the resulting or deduplicated booking
     * @param Event $event the target event
     * @param Attendee $attendee the attendee associated with the booking
     * @param bool $created true if newly created, false if returned from idempotency cache
     */
    public function __construct(
        public Booking $booking,
        public Event $event,
        public Attendee $attendee,
        public bool $created,
    ) {}
}
