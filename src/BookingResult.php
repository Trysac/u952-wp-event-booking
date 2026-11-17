<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Result of a booking operation, pairing the persisted booking with e-mail delivery status.
 */
final readonly class BookingResult
{
    /**
     * Creates a booking result.
     *
     * @param Booking $booking the confirmed or waitlisted booking
     * @param bool $emailSent true if the notification e-mail was dispatched without error
     */
    public function __construct(
        public Booking $booking,
        public bool $emailSent,
    ) {}
}
