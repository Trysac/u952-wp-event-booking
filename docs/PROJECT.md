# Project record

## Objective

Event booking core with seat capacity, FIFO waitlists, idempotent requests and atomic conditional updates that never oversell under concurrent bookings, ready for WordPress.

## Scope

- Pure PHP 8.4 domain and persistence core.
- SQLite and In-Memory storage implementations.
- Atomic concurrency-safe bookings.
- Header-injection-safe mailer contract.

## Out of Scope

- Payments.
- Seat maps.
- Recurring events.
- Time zones in display.
- The WordPress plugin shell (admin screens, REST routes, $wpdb adapters).

## Effort metrics

| Metric | Value |
|---|---|
| Tokens (input / output) | 2873151 / 33226 |
| Hours (human) | pending measurement |
| Cost (USD) | 1.0727 |

## Factory run u952 (measured from API usage)

| Date (UTC) | Phase | Model | Batch | Input | Output | Cache write | Cache read | Cost (USD) |
|---|---|---|---|---|---|---|---|---|
| 2026-11-17 | generation | google/gemini-4-argon | yes | 5880 | 23397 | 0 | 0 | 0.4915 |
| 2026-11-17 | fix-1 | google/gemini-3.8-flash | no | 141978 | 4562 | 0 | 1012660 | 0.1995 |
| 2026-11-17 | fix-2 | google/gemini-3.8-flash | no | 82572 | 2325 | 0 | 310404 | 0.0939 |
| 2026-11-17 | audit | google/gemini-3.8-flash | no | 131621 | 876 | 0 | 482406 | 0.1382 |
| 2026-11-17 | threat-model | google/gemini-3.8-flash | no | 130198 | 796 | 0 | 553145 | 0.1421 |
| 2026-11-17 | docs | google/gemini-3.1-flash-lite | no | 22287 | 1270 | 0 | 0 | 0.0075 |

Total: $1.0727. Prices: vertex line, as measured by its adapter. Providers serve the current version of each model id, so the date tells which version answered; versions, launch stages and price sources per line are in the factory's model register (docs/MODELS.md).
