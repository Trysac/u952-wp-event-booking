# Architecture

## Purpose

`trysac/u952-wp-event-booking` is a small, dependency-free PHP library: Event booking core with seat capacity, FIFO waitlists, idempotent requests and atomic conditional updates that never oversell under concurrent bookings, ready for WordPress

## Shape

```text
caller code --> namespace Trysac\WpEventBooking (src/, PSR-4)
                    |
                    +-- final classes with static, pure functions; no I/O, no global mutable state
                    +-- @internal helpers (optional)
```

- **Public API**: every public class, method, constant and property under `src/` that is not
  marked `@internal`. Internal helpers may change without a major version.
- **Errors**: invalid input throws an `\InvalidArgumentException` subclass of the library (such as
  `BlankInputException`) documented with `@throws`. The library never echoes, exits or changes
  global state (ini settings, error handlers, the locale).
- **Side effects**: none. If a feature needs I/O, accept a stream resource, a string or a small
  interface instead of opening resources, and record the choice in an ADR.

## Quality contract

`scripts/check.php` is the single definition of the gates; `fabrica.json` tells the factory and CI
how to run it. See [TESTING.md](TESTING.md).

## Decisions

Architecture decisions are recorded in [decisions/](decisions/) as numbered ADRs.
