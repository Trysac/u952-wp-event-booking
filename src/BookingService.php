<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Coordinates seat validation, atomic persistence, waitlist promotion, and post-commit notifications.
 */
final class BookingService
{
    /**
     * Maximum allowed length for an idempotency key.
     */
    public const int MAX_IDEMPOTENCY_KEY_LENGTH = 200;

    /**
     * Creates a booking service.
     *
     * @param BookingStore $store atomic persistence store
     * @param Mailer $mailer outbound e-mail sender
     * @param ClockInterface $clock time source
     * @param IdGenerator $ids booking ID generator
     * @param ?(\Closure(array<string, mixed>): void) $onEvent optional diagnostic callback receiving structured event arrays
     * @param int $maxSeatsPerBooking upper bound on seats per single booking request
     */
    public function __construct(
        private readonly BookingStore $store,
        private readonly Mailer $mailer,
        private readonly ClockInterface $clock,
        private readonly IdGenerator $ids,
        private readonly ?\Closure $onEvent = null,
        private readonly int $maxSeatsPerBooking = 10,
    ) {}

    /**
     * Books seats on an event or returns the previously created booking for the same idempotency key.
     *
     * @param string $eventId target event identifier
     * @param Attendee $who validated attendee details
     * @param int $seats number of seats to reserve (1..maxSeatsPerBooking)
     * @param string $idempotencyKey client-supplied deduplication key
     *
     * @throws BookingException on invalid inputs, closed event, or full capacity/waitlist
     */
    public function book(string $eventId, Attendee $who, int $seats, string $idempotencyKey): BookingResult
    {
        if ($seats < 1 || $seats > $this->maxSeatsPerBooking) {
            throw new BookingException(
                'invalid_seats',
                sprintf('Seats must be between 1 and %d.', $this->maxSeatsPerBooking),
            );
        }
        if ('' === trim($idempotencyKey) || mb_strlen($idempotencyKey, 'UTF-8') > self::MAX_IDEMPOTENCY_KEY_LENGTH) {
            throw new BookingException('invalid_name', 'Idempotency key must be between 1 and 200 characters.');
        }

        $candidateId = $this->ids->nextId();
        $outcome = $this->store->bookAtomic(
            $eventId,
            $candidateId,
            $who,
            $seats,
            $idempotencyKey,
            $this->clock->now(),
        );

        if (!$outcome->created) {
            return new BookingResult($outcome->booking, true);
        }

        $message = Booking::STATUS_CONFIRMED === $outcome->booking->status
            ? Messages::confirmation($outcome->event, $outcome->booking, $outcome->attendee)
            : Messages::waitlisted($outcome->event, $outcome->booking, $outcome->attendee);

        $emailSent = $this->sendSafely($message, $outcome->booking->id);

        return new BookingResult($outcome->booking, $emailSent);
    }

    /**
     * Cancels an existing booking and promotes waitlisted bookings whose seat count fits.
     *
     * @param string $bookingId booking identifier to cancel
     *
     * @throws BookingException if the booking is unknown or already canceled
     */
    public function cancel(string $bookingId): Booking
    {
        if ('' === trim($bookingId) || mb_strlen($bookingId, 'UTF-8') > 200) {
            throw new BookingException('unknown_booking', 'Booking ID is invalid.');
        }

        $outcome = $this->store->cancelAtomic($bookingId);

        foreach ($outcome->promoted as $promotedItem) {
            $confirmMsg = Messages::confirmation(
                $outcome->event,
                $promotedItem['booking'],
                $promotedItem['attendee'],
            );
            $this->sendSafely($confirmMsg, $promotedItem['booking']->id);
        }

        return $outcome->canceled;
    }

    /**
     * Returns the remaining available seats for an event.
     *
     * @param string $eventId event identifier
     *
     * @throws BookingException if the event does not exist
     */
    public function availability(string $eventId): int
    {
        return $this->store->availability($eventId);
    }

    /**
     * Sends an e-mail after transaction commit without rolling back on failure or leaking PII in telemetry.
     */
    private function sendSafely(Message $message, string $bookingId): bool
    {
        try {
            $this->mailer->send($message);

            return true;
        } catch (\Throwable) {
            if (null !== $this->onEvent) {
                ($this->onEvent)([
                    'type' => 'mail_failed',
                    'bookingId' => $bookingId,
                ]);
            }

            return false;
        }
    }
}
