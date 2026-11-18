# Security & Threat Model

## Threat Model

1. **Race Conditions / Double Booking (TOCTOU)**:
   - *Defended*: `PdoStore` performs bookings inside a transaction (`BEGIN IMMEDIATE` on SQLite). Capacity updates are performed via a single atomic `UPDATE` statement that enforces capacity constraints.
2. **SMTP / E-mail Header Injection**:
   - *Defended*: `Attendee` and `Message` objects validate recipient addresses via `filter_var` and block strings containing CR/LF characters. Subject lines are checked for CR/LF.
3. **Unbounded Input**:
   - *Defended*: Name and Identifier lengths capped at 200 characters; email addresses at 254; `BookingException` messages truncated at 200 chars.
4. **PII Leakage in Telemetry**:
   - *Defended*: If a mailer fails after transaction commit, diagnostic callbacks receive only `['type' => 'mail_failed', 'bookingId' => $id]`.

## Limits

- Max seats per booking: Default 10 (configurable).
- Waitlist capacity: Default 100.
- Idempotency key length: 200 chars.
- Database: SQLite (via PDO), requires `pdo_sqlite` extension.
