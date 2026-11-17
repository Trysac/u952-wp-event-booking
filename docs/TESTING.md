# Testing

## Test Coverage

Tests are located in `tests/` and cover:

- `BookingServiceTest.php`: Acceptance and edge cases, including:
    - Capacity and waitlist promotion logic.
    - Concurrency simulation (tests verify invariant consistency).
    - Idempotency key behavior.
    - Validation for attendee, header injection, and email constraints.
    - Clock-dependent event closure logic.
- `PdoStore` specific checks for FIFO waitlist promotion order.

## Policy

- No runtime dependencies in tests.
- Tests are deterministic (fixed clocks, controlled random generators).
- Line coverage target: 80%+.
