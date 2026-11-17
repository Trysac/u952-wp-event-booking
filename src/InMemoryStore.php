<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * Bounded in-memory booking store enforcing the same invariants as PdoStore.
 */
final class InMemoryStore implements BookingStore
{
    /**
     * Maximum number of events allowed in memory.
     */
    public const int MAX_EVENTS = 1000;

    /**
     * Maximum number of bookings allowed in memory.
     */
    public const int MAX_BOOKINGS = 50000;

    /**
     * @var array<string, Event>
     */
    private array $events = [];

    /**
     * @var array<string, int>
     */
    private array $bookedByEvent = [];

    /**
     * @var array<string, Booking>
     */
    private array $bookings = [];

    /**
     * @var array<string, Attendee>
     */
    private array $attendees = [];

    /**
     * @var array<string, string>
     */
    private array $idempotencyKeys = [];

    /**
     * Persists a new event in memory.
     *
     * @param Event $e event to store
     */
    public function createEvent(Event $e): void
    {
        if (count($this->events) >= self::MAX_EVENTS && !isset($this->events[$e->id])) {
            throw new BookingException('storage_error', 'Maximum number of in-memory events reached.');
        }
        $this->events[$e->id] = $e;
        $this->bookedByEvent[$e->id] ??= 0;
    }

    /**
     * Books seats or deduplicates by idempotency key.
     */
    public function bookAtomic(
        string $eventId,
        string $bookingId,
        Attendee $who,
        int $seats,
        string $idempotencyKey,
        \DateTimeImmutable $now,
    ): StoreBookOutcome {
        if (isset($this->idempotencyKeys[$idempotencyKey])) {
            $existingId = $this->idempotencyKeys[$idempotencyKey];
            $existing = $this->bookings[$existingId] ?? null;
            if (null === $existing) {
                throw new BookingException('storage_error', 'Corrupted idempotency reference.');
            }
            $event = $this->events[$existing->eventId] ?? null;
            $attendee = $this->attendees[$existingId] ?? null;
            if (null === $event || null === $attendee) {
                throw new BookingException('storage_error', 'Corrupted booking state.');
            }

            return new StoreBookOutcome($existing, $event, $attendee, false);
        }

        $event = $this->events[$eventId] ?? null;
        if (null === $event) {
            throw new BookingException('unknown_event', sprintf('Unknown event: %s', $eventId));
        }

        if ($now >= $event->startsAt) {
            throw new BookingException('event_closed', 'Bookings are closed for this event.');
        }

        if (count($this->bookings) >= self::MAX_BOOKINGS) {
            throw new BookingException('storage_error', 'Maximum number of in-memory bookings reached.');
        }

        $currentBooked = $this->bookedByEvent[$eventId] ?? 0;
        if ($currentBooked + $seats <= $event->capacity) {
            $this->bookedByEvent[$eventId] = $currentBooked + $seats;
            $status = Booking::STATUS_CONFIRMED;
        } else {
            $waitlistedCount = $this->countWaitlisted($eventId);
            if ($waitlistedCount + 1 > $event->waitlistCapacity) {
                throw new BookingException('sold_out', 'Event and waitlist are full.');
            }
            $status = Booking::STATUS_WAITLISTED;
        }

        $booking = new Booking($bookingId, $eventId, $seats, $status, $now);
        $this->bookings[$bookingId] = $booking;
        $this->attendees[$bookingId] = $who;
        $this->idempotencyKeys[$idempotencyKey] = $bookingId;

        return new StoreBookOutcome($booking, $event, $who, true);
    }

    /**
     * Cancels a booking and promotes eligible waitlisted bookings in creation order.
     */
    public function cancelAtomic(string $bookingId): StoreCancelOutcome
    {
        $existing = $this->bookings[$bookingId] ?? null;
        if (null === $existing) {
            throw new BookingException('unknown_booking', sprintf('Unknown booking: %s', $bookingId));
        }
        if (Booking::STATUS_CANCELED === $existing->status) {
            throw new BookingException('already_canceled', sprintf('Booking %s is already canceled.', $bookingId));
        }

        $event = $this->events[$existing->eventId] ?? null;
        $attendee = $this->attendees[$bookingId] ?? null;
        if (null === $event || null === $attendee) {
            throw new BookingException('storage_error', 'Corrupted booking state.');
        }

        $wasConfirmed = Booking::STATUS_CONFIRMED === $existing->status;
        $canceled = new Booking(
            $existing->id,
            $existing->eventId,
            $existing->seats,
            Booking::STATUS_CANCELED,
            $existing->createdAt,
        );
        $this->bookings[$bookingId] = $canceled;

        $promoted = [];
        if ($wasConfirmed) {
            $this->bookedByEvent[$event->id] = ($this->bookedByEvent[$event->id] ?? 0) - $existing->seats;
            foreach ($this->bookings as $id => $candidate) {
                if ($candidate->eventId !== $event->id || Booking::STATUS_WAITLISTED !== $candidate->status) {
                    continue;
                }
                $currentBooked = $this->bookedByEvent[$event->id] ?? 0;
                if ($currentBooked + $candidate->seats <= $event->capacity) {
                    $this->bookedByEvent[$event->id] = $currentBooked + $candidate->seats;
                    $confirmed = new Booking(
                        $candidate->id,
                        $candidate->eventId,
                        $candidate->seats,
                        Booking::STATUS_CONFIRMED,
                        $candidate->createdAt,
                    );
                    $this->bookings[$id] = $confirmed;
                    $candidateAttendee = $this->attendees[$id] ?? null;
                    if (null !== $candidateAttendee) {
                        $promoted[] = ['booking' => $confirmed, 'attendee' => $candidateAttendee];
                    }
                }
            }
        }

        return new StoreCancelOutcome($canceled, $event, $attendee, $promoted);
    }

    /**
     * Returns remaining seats for the event.
     */
    public function availability(string $eventId): int
    {
        $event = $this->events[$eventId] ?? null;
        if (null === $event) {
            throw new BookingException('unknown_event', sprintf('Unknown event: %s', $eventId));
        }
        $booked = $this->bookedByEvent[$eventId] ?? 0;

        return max(0, $event->capacity - $booked);
    }

    /**
     * Returns the stored booked seat counter for invariant verification.
     *
     * @param string $eventId event identifier
     */
    public function bookedSeats(string $eventId): int
    {
        if (!isset($this->events[$eventId])) {
            throw new BookingException('unknown_event', sprintf('Unknown event: %s', $eventId));
        }

        return $this->bookedByEvent[$eventId] ?? 0;
    }

    /**
     * Lists all bookings for an event in creation order.
     *
     * @return list<Booking>
     */
    public function listBookings(string $eventId): array
    {
        if (!isset($this->events[$eventId])) {
            throw new BookingException('unknown_event', sprintf('Unknown event: %s', $eventId));
        }
        $result = [];
        foreach ($this->bookings as $booking) {
            if ($booking->eventId === $eventId) {
                $result[] = $booking;
            }
        }

        return $result;
    }

    /**
     * Counts currently waitlisted bookings for an event.
     */
    private function countWaitlisted(string $eventId): int
    {
        $count = 0;
        foreach ($this->bookings as $booking) {
            if ($booking->eventId === $eventId && Booking::STATUS_WAITLISTED === $booking->status) {
                ++$count;
            }
        }

        return $count;
    }
}
