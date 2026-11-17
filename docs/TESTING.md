# Testing

## Running

```sh
php vendor/bin/phpunit   # PHPUnit tests, property tests included
php scripts/check.php    # every gate, as CI and the factory run it
```

## Policy

- Line coverage of `src/` (measured by PHPUnit with pcov or Xdebug) must stay at or above 80 %;
  the gate fails below it.
- Tests exercise the public API. They assert every observable effect: returned values, thrown
  exceptions (`expectException`) and their messages.
- Property tests state invariants such as idempotence or round trips: a data provider generates
  inputs with `Random\Randomizer` over a seeded `Mt19937` engine, so every run checks the same cases.
- Each bug fix starts with a failing test that reproduces it.
- Tests are deterministic: no network, no wall clock, no dependence on test order (PHPUnit runs
  them in random order).

## Tiers

- Local tier (every gate run, coverage measured here): unit and property tests, plus conformance
  on small fixtures kept in the repository.
- CI tier: conformance that needs an external tool or a download. Mark the test class or method
  with `#[Group('conformance')]`; the tests gate excludes the group (`--exclude-group
  conformance`) unless `scripts/check.php` runs with `FABRICA_CI=1`, while PHPStan always
  analyses it. The CI `conformance` job sets `FABRICA_CI=1` and runs the full suite. Tools are
  installed by the optional `ci/conformance-setup.sh` (bash, CI only; verify every download
  against a pinned SHA-256), and tests call `self::markTestSkipped()` with a clear message when
  their tool is missing.

## Layout

Tests live in `tests/` in the namespace `Trysac\WpEventBooking\Tests`, one `*Test.php` class per
area. Fixtures and golden files go in `tests/fixtures/` (read them with `__DIR__ . '/fixtures/...'`).
