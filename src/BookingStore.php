<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Persistence contract for events, atomic seat bookings, waitlists, and idempotency keys.
 */
interface BookingStore
{
    /**
     * Registers a new event in the store.
     *
     * @param Event $e event to persist
     */
    public function createEvent(Event $e): void;

    /**
     * Atomically records a booking or returns an existing booking for the idempotency key.
     *
     * @param string $eventId target event identifier
     * @param string $bookingId candidate booking identifier if newly created
     * @param Attendee $who validated attendee
     * @param int $seats validated seat count
     * @param string $idempotencyKey client idempotency token
     * @param \DateTimeImmutable $now current timestamp
     *
     * @throws BookingException on unknown event, closed event, or full waitlist
     */
    public function bookAtomic(
        string $eventId,
        string $bookingId,
        Attendee $who,
        int $seats,
        string $idempotencyKey,
        \DateTimeImmutable $now,
    ): StoreBookOutcome;

    /**
     * Atomically cancels a booking and promotes eligible waitlisted bookings in FIFO order.
     *
     * @param string $bookingId identifier of the booking to cancel
     *
     * @throws BookingException if the booking does not exist or is already canceled
     */
    public function cancelAtomic(string $bookingId): StoreCancelOutcome;

    /**
     * Returns the number of remaining available seats for an event.
     *
     * @param string $eventId event identifier
     *
     * @throws BookingException if the event does not exist
     */
    public function availability(string $eventId): int;

    /**
     * Returns all bookings for an event in creation order.
     *
     * @param string $eventId event identifier
     *
     * @return list<Booking>
     *
     * @throws BookingException if the event does not exist
     */
    public function listBookings(string $eventId): array;
}
