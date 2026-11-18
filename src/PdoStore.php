<?php

declare(strict_types=1);

namespace Trysac\WpEventBooking;

/**
 * PDO-backed booking store using single conditional updates inside immediate transactions.
 */
final class PdoStore implements BookingStore
{
    /**
     * Whether the underlying driver is SQLite.
     */
    private readonly bool $isSqlite;

    /**
     * Monotonic sequence number within this store instance to preserve FIFO ordering on equal timestamps.
     */
    private int $seq = 0;

    /**
     * Configures the PDO connection with error exceptions and a 5-second busy timeout.
     *
     * @param \PDO $pdo active PDO database connection
     */
    public function __construct(
        private readonly \PDO $pdo,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->isSqlite = 'sqlite' === $driver;
        if ($this->isSqlite) {
            $this->pdo->setAttribute(\PDO::ATTR_TIMEOUT, 5);
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Creates the events, bookings, and idempotency_keys tables idempotently.
     */
    public function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS events (
                id TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                starts_at TEXT NOT NULL,
                capacity INTEGER NOT NULL,
                waitlist_capacity INTEGER NOT NULL,
                booked INTEGER NOT NULL DEFAULT 0
            )',
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS bookings (
                seq INTEGER PRIMARY KEY AUTOINCREMENT,
                id TEXT NOT NULL UNIQUE,
                event_id TEXT NOT NULL,
                attendee_name TEXT NOT NULL,
                attendee_email TEXT NOT NULL,
                seats INTEGER NOT NULL,
                status TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS idempotency_keys (
                idempotency_key TEXT PRIMARY KEY,
                booking_id TEXT NOT NULL
            )',
        );
    }

    /**
     * Inserts or replaces an event definition.
     *
     * @param Event $e event to persist
     */
    public function createEvent(Event $e): void
    {
        $stmt = $this->prepare(
            'INSERT INTO events (id, title, starts_at, capacity, waitlist_capacity, booked)
             VALUES (:id, :title, :starts_at, :capacity, :waitlist_capacity, 0)
             ON CONFLICT(id) DO UPDATE SET
                title = excluded.title,
                starts_at = excluded.starts_at,
                capacity = excluded.capacity,
                waitlist_capacity = excluded.waitlist_capacity',
        );
        $stmt->execute([
            ':id' => $e->id,
            ':title' => $e->title,
            ':starts_at' => $e->startsAt->format(\DateTimeInterface::ATOM),
            ':capacity' => $e->capacity,
            ':waitlist_capacity' => $e->waitlistCapacity,
        ]);
    }

    /**
     * Runs a booking inside a transaction using a conditional seat update.
     */
    public function bookAtomic(
        string $eventId,
        string $bookingId,
        Attendee $who,
        int $seats,
        string $idempotencyKey,
        \DateTimeImmutable $now,
    ): StoreBookOutcome {
        $this->beginTransaction();
        try {
            $existingId = $this->findBookingIdByKey($idempotencyKey);
            if (null !== $existingId) {
                $loaded = $this->loadBookingWithEvent($existingId);
                $this->commit();

                return new StoreBookOutcome($loaded['booking'], $loaded['event'], $loaded['attendee'], false);
            }

            $event = $this->loadEvent($eventId);
            if (null === $event) {
                throw new BookingException('unknown_event', sprintf('Unknown event: %s', $eventId));
            }
            if ($now >= $event->startsAt) {
                throw new BookingException('event_closed', 'Bookings are closed for this event.');
            }

            $keyStmt = $this->prepare('INSERT INTO idempotency_keys (idempotency_key, booking_id) VALUES (:k, :b)');
            $keyStmt->execute([':k' => $idempotencyKey, ':b' => $bookingId]);

            $updateStmt = $this->prepare('UPDATE events SET booked = booked + :n WHERE id = :id AND booked + :n <= capacity');
            $updateStmt->execute([':n' => $seats, ':id' => $eventId]);

            if (1 === $updateStmt->rowCount()) {
                $status = Booking::STATUS_CONFIRMED;
            } else {
                $waitCountStmt = $this->prepare('SELECT COUNT(*) FROM bookings WHERE event_id = :id AND status = :s');
                $waitCountStmt->execute([':id' => $eventId, ':s' => Booking::STATUS_WAITLISTED]);
                $waitlistedCount = (int) $waitCountStmt->fetchColumn();
                if ($waitlistedCount + 1 > $event->waitlistCapacity) {
                    throw new BookingException('sold_out', 'Event and waitlist are full.');
                }
                $status = Booking::STATUS_WAITLISTED;
            }

            ++$this->seq;
            $createdAtStr = $now->format('Y-m-d\TH:i:s.uP');
            $insertBooking = $this->prepare(
                'INSERT INTO bookings (id, event_id, attendee_name, attendee_email, seats, status, created_at)
             VALUES (:id, :event_id, :name, :email, :seats, :status, :created_at)',
            );
            $insertBooking->execute([
                ':id' => $bookingId,
                ':event_id' => $eventId,
                ':name' => $who->name,
                ':email' => $who->email,
                ':seats' => $seats,
                ':status' => $status,
                ':created_at' => $createdAtStr,
            ]);

            $booking = new Booking($bookingId, $eventId, $seats, $status, $now);
            $this->commit();

            return new StoreBookOutcome($booking, $event, $who, true);
        } catch (BookingException $e) {
            $this->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw new BookingException('storage_error', 'Database error during booking: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Cancels a booking and promotes waitlisted bookings in FIFO order inside one transaction.
     */
    public function cancelAtomic(string $bookingId): StoreCancelOutcome
    {
        $this->beginTransaction();
        try {
            $loaded = $this->loadBookingWithEventOrNull($bookingId);
            if (null === $loaded) {
                throw new BookingException('unknown_booking', sprintf('Unknown booking: %s', $bookingId));
            }
            $existing = $loaded['booking'];
            $event = $loaded['event'];
            $attendee = $loaded['attendee'];

            if (Booking::STATUS_CANCELED === $existing->status) {
                throw new BookingException('already_canceled', sprintf('Booking %s is already canceled.', $bookingId));
            }

            $cancelStmt = $this->prepare(
                'UPDATE bookings SET status = :s WHERE id = :id',
            );
            $cancelStmt->execute([':s' => Booking::STATUS_CANCELED, ':id' => $bookingId]);

            $canceled = new Booking(
                $existing->id,
                $existing->eventId,
                $existing->seats,
                Booking::STATUS_CANCELED,
                $existing->createdAt,
            );

            $promoted = [];
            if (Booking::STATUS_CONFIRMED === $existing->status) {
                $decStmt = $this->prepare(
                    'UPDATE events SET booked = booked - :n WHERE id = :id',
                );
                $decStmt->execute([':n' => $existing->seats, ':id' => $event->id]);

                $waitStmt = $this->prepare(
                    'SELECT id, event_id, attendee_name, attendee_email, seats, status, created_at
                     FROM bookings
                     WHERE event_id = :id AND status = :s
                     ORDER BY created_at ASC, seq ASC',
                );
                $waitStmt->execute([':id' => $event->id, ':s' => Booking::STATUS_WAITLISTED]);
                /** @var list<array{id: string, event_id: string, attendee_name: string, attendee_email: string, seats: int|numeric-string, status: string, created_at: string}> $rows */
                $rows = $waitStmt->fetchAll();

                $incStmt = $this->prepare(
                    'UPDATE events SET booked = booked + :n WHERE id = :id AND booked + :n <= capacity',
                );
                $promoteStmt = $this->prepare(
                    'UPDATE bookings SET status = :s WHERE id = :id',
                );

                foreach ($rows as $row) {
                    $candidateSeats = (int) $row['seats'];
                    $incStmt->execute([':n' => $candidateSeats, ':id' => $event->id]);
                    if (1 === $incStmt->rowCount()) {
                        $promoteStmt->execute([':s' => Booking::STATUS_CONFIRMED, ':id' => $row['id']]);
                        $promotedBooking = new Booking(
                            $row['id'],
                            $row['event_id'],
                            $candidateSeats,
                            Booking::STATUS_CONFIRMED,
                            new \DateTimeImmutable($row['created_at']),
                        );
                        $promotedAttendee = new Attendee($row['attendee_name'], $row['attendee_email']);
                        $promoted[] = ['booking' => $promotedBooking, 'attendee' => $promotedAttendee];
                    }
                }
            }

            $this->commit();

            return new StoreCancelOutcome($canceled, $event, $attendee, $promoted);
        } catch (BookingException $e) {
            $this->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw new BookingException('storage_error', 'Database error during cancellation: ' . $e->getMessage(), $e);
        }
    }

    /**
     * Returns remaining seat capacity for an event.
     */
    public function availability(string $eventId): int
    {
        $stmt = $this->prepare('SELECT capacity, booked FROM events WHERE id = :id');
        $stmt->execute([':id' => $eventId]);
        /** @var array{capacity: int|numeric-string, booked: int|numeric-string}|false $row */
        $row = $stmt->fetch();
        if (false === $row) {
            throw new BookingException('unknown_event', sprintf('Unknown event: %s', $eventId));
        }

        return max(0, (int) $row['capacity'] - (int) $row['booked']);
    }

    /**
     * Lists all bookings for an event in creation order.
     *
     * @return list<Booking>
     */
    public function listBookings(string $eventId): array
    {
        if (null === $this->loadEvent($eventId)) {
            throw new BookingException('unknown_event', sprintf('Unknown event: %s', $eventId));
        }
        $stmt = $this->prepare(
            'SELECT id, event_id, seats, status, created_at FROM bookings WHERE event_id = :id ORDER BY created_at ASC, seq ASC',
        );
        $stmt->execute([':id' => $eventId]);
        /** @var list<array{id: string, event_id: string, seats: int|numeric-string, status: string, created_at: string}> $rows */
        $rows = $stmt->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[] = new Booking(
                $row['id'],
                $row['event_id'],
                (int) $row['seats'],
                $row['status'],
                new \DateTimeImmutable($row['created_at']),
            );
        }

        return $result;
    }

    /**
     * Starts an immediate transaction on SQLite or a standard PDO transaction elsewhere.
     */
    private function beginTransaction(): void
    {
        if ($this->isSqlite) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
    }

    /**
     * Commits the current transaction.
     */
    private function commit(): void
    {
        if ($this->isSqlite) {
            $this->pdo->exec('COMMIT');
        } else {
            $this->pdo->commit();
        }
    }

    /**
     * Rolls back the current transaction safely.
     */
    private function rollBack(): void
    {
        try {
            if ($this->isSqlite) {
                $this->pdo->exec('ROLLBACK');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (\Throwable) {
            // Ignore rollback errors if transaction already closed.
        }
    }

    /**
     * Looks up a booking ID by idempotency key.
     */
    private function findBookingIdByKey(string $key): ?string
    {
        $stmt = $this->prepare('SELECT booking_id FROM idempotency_keys WHERE idempotency_key = :k');
        $stmt->execute([':k' => $key]);
        /** @var string|false $val */
        $val = $stmt->fetchColumn();

        return false === $val ? null : $val;
    }

    /**
     * Loads an Event by ID or returns null.
     */
    private function loadEvent(string $eventId): ?Event
    {
        $stmt = $this->prepare(
            'SELECT id, title, starts_at, capacity, waitlist_capacity FROM events WHERE id = :id',
        );
        $stmt->execute([':id' => $eventId]);
        /** @var array{id: string, title: string, starts_at: string, capacity: int|numeric-string, waitlist_capacity: int|numeric-string}|false $row */
        $row = $stmt->fetch();
        if (false === $row) {
            return null;
        }

        return new Event(
            $row['id'],
            $row['title'],
            new \DateTimeImmutable($row['starts_at']),
            (int) $row['capacity'],
            (int) $row['waitlist_capacity'],
        );
    }

    /**
     * Loads a booking along with its event and attendee, or returns null.
     *
     * @return ?array{booking: Booking, event: Event, attendee: Attendee}
     */
    private function loadBookingWithEventOrNull(string $bookingId): ?array
    {
        $stmt = $this->prepare(
            'SELECT id, event_id, attendee_name, attendee_email, seats, status, created_at FROM bookings WHERE id = :id',
        );
        $stmt->execute([':id' => $bookingId]);
        /** @var array{id: string, event_id: string, attendee_name: string, attendee_email: string, seats: int|numeric-string, status: string, created_at: string}|false $row */
        $row = $stmt->fetch();
        if (false === $row) {
            return null;
        }
        $event = $this->loadEvent($row['event_id']);
        if (null === $event) {
            return null;
        }

        return [
            'booking' => new Booking(
                $row['id'],
                $row['event_id'],
                (int) $row['seats'],
                $row['status'],
                new \DateTimeImmutable($row['created_at']),
            ),
            'event' => $event,
            'attendee' => new Attendee($row['attendee_name'], $row['attendee_email']),
        ];
    }

    /**
     * Loads a booking along with its event and attendee, throwing if missing.
     *
     * @return array{booking: Booking, event: Event, attendee: Attendee}
     */
    private function loadBookingWithEvent(string $bookingId): array
    {
        $loaded = $this->loadBookingWithEventOrNull($bookingId);
        if (null === $loaded) {
            throw new BookingException('unknown_booking', sprintf('Unknown booking: %s', $bookingId));
        }

        return $loaded;
    }

    /**
     * Prepares an SQL statement and throws a storage error if preparation fails.
     *
     * @param string $sql SQL query to prepare
     *
     *
     * @throws BookingException on statement preparation failure
     */
    private function prepare(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if (false === $stmt) {
            throw new BookingException('storage_error', 'Failed to prepare database statement.');
        }

        return $stmt;
    }
}
