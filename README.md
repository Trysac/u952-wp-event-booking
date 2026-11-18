# wp-event-booking

Event booking core with seat capacity, FIFO waitlists, idempotent requests and atomic conditional updates that never oversell under concurrent bookings, ready for WordPress.

## Problem

Small venues sell seats through WordPress plugins that read the remaining capacity, then write a booking, so two visitors at the same moment both get the last seat. Plugins also send confirmation e-mails from untrusted form fields. This package books seats with a single conditional update inside a transaction, manages a waitlist, deduplicates retries with idempotency keys and builds e-mails that refuse header injection.

## Installation

```bash
composer require trysac/u952-wp-event-booking
```

## Usage

```php
<?php
declare(strict_types=1);
use Trysac\WpEventBooking\{Attendee, BookingService, Event, FrozenClock, InMemoryStore, RecordingMailer, SequentialIds};

$store = new InMemoryStore();
$store->createEvent(new Event('launch', 'Launch party', new \DateTimeImmutable('2026-06-01T18:00:00Z'), 2));
$service = new BookingService($store, new RecordingMailer(), new FrozenClock(), new SequentialIds());
$result = $service->book('launch', new Attendee('Ada Lovelace', 'ada@example.test'), 1, 'req-1');
```

## Summary

- **Seat Capacity**: `PdoStore` uses atomic updates (`UPDATE events SET booked = booked + :n WHERE id = :id AND booked + :n <= capacity`) to prevent overselling.
- **Waitlists**: Automatically manages FIFO waitlists when capacity is reached.
- **Idempotency**: Requests must provide an idempotency key to deduplicate retries.
- **Security**: Strict input validation on `Attendee` and `Message` prevents header injection.

## Why not existing alternatives?

Existing WordPress booking plugins often rely on read-modify-write patterns, which fail under concurrency (TOCTOU). This package guarantees atomic reservations using database transactions and conditional updates, validated by multi-process concurrency tests.

## Documentation

- See [Security](docs/SECURITY.md) for threat models and limits.
