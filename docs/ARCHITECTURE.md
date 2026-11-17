# Architecture

## Overview

`trysac/wp-event-booking` is the pure PHP 8.4 domain and persistence core for an event booking system designed to be embedded in a WordPress plugin or standalone service.

## Components

- **`Event` / `Attendee` / `Booking` / `BookingResult` / `Message`**: Immutable (`final readonly`) value objects that validate invariants on construction.
- **`BookingService`**: Application service enforcing seat request bounds, delegating atomic state transitions to `BookingStore`, and dispatching messages via `Mailer` after transaction commit.
- **`BookingStore` (`PdoStore` & `InMemoryStore`)**:
  - `PdoStore` uses `BEGIN IMMEDIATE` on SQLite (with `PDO::ATTR_TIMEOUT = 5`) and a single conditional update (`UPDATE events SET booked = booked + :n WHERE id = :id AND booked + :n <= capacity`) so concurrent processes never oversell.
  - `InMemoryStore` enforces the exact same invariants in memory for fast unit and stateful property testing.
- **`Messages` & `Mailer` (`RecordingMailer`)**: Constructs plain-text confirmation, waitlist, and cancellation messages and rejects any CR or LF characters in recipient addresses or subjects.
- **`Cli`**: Command-line runner (`availability` and `list`) returning POSIX exit codes (`0` success, `1` domain error, `2` usage or I/O error).
