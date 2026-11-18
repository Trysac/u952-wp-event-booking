# Atomic conditional seat updates and post-commit e-mail dispatch

Status: accepted (generated, pending human review)

## Context

Event booking plugins commonly read available seats in application code and then insert a booking row, allowing concurrent requests to oversell the venue. In addition, sending confirmation e-mails inside the database transaction risks holding locks across network I/O or rolling back committed seats when an SMTP server fails.

## Decision

Enforce seat capacity with a single conditional SQL update (UPDATE events SET booked = booked + :n WHERE id = :id AND booked + :n <= capacity) inside a transaction (BEGIN IMMEDIATE on SQLite) alongside primary-key idempotency deduplication and FIFO waitlist promotion. Send all notification e-mails strictly after transaction commit and report mailer errors via a PII-free callback without rolling back the booking.

## Consequences

Concurrent processes can never oversell capacity, retries with the same idempotency key are deduplicated safely, waitlisted attendees whose seats fit are promoted deterministically in creation order on cancellation, and transient e-mail failures never lose a confirmed seat.
