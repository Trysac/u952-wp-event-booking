# AGENTS.md

Instructions for coding agents (and humans) working on the Composer package `trysac/u952-wp-event-booking`
(namespace `Trysac\WpEventBooking`).

## Commands

| Task | Command |
|---|---|
| Install dependencies | `composer install` |
| Run every gate (must pass before a PR) | `php scripts/check.php` |
| Tests only | `php vendor/bin/phpunit` |
| Format | `php vendor/bin/php-cs-fixer fix` |
| Lint | `php vendor/bin/phpstan analyse` (level max, strict rules) |
| Coverage | `php scripts/check.php tests` |

The gate sequence is: PHP-CS-Fixer in check mode (PER Coding Style and `declare(strict_types=1)`),
lint (`composer validate --strict`, which also fails when `composer.lock` is out of date,
`declare(strict_types=1)` in every PHP file, and PHPStan at level max with the strict rules),
Semgrep (the factory's rules in `.semgrep/`), PHPUnit under pcov with line coverage >= 80 % of
`src/`, documentation (`docs-manifest.json` plus a docblock on every public class, method,
constant and property of `src/`), gitleaks, `composer audit` over `composer.lock`, and
OSV-Scanner over `composer.lock`. It stops at the first failure.

## Folder map

| Path | Content |
|---|---|
| `src/` | The library, PSR-4 namespace `Trysac\WpEventBooking\`; its public classes are the public API. |
| `tests/` | PHPUnit tests (`*Test.php`, namespace `Trysac\WpEventBooking\Tests`), including property tests. |
| `tests/fixtures/` | Optional fixtures and golden files used by tests. |
| `scripts/` | The single definition of the quality gates (`check.php`) and the factory's dependency tool. |
| `docs/` | Architecture, decisions (ADRs), testing, security, project record, stack. |
| `fabrica.json` | Install and check commands used by the factory and CI. |
| `docs-manifest.json` | Documentation files the docs gate requires. |
| `.github/` | CI wrapper and Dependabot configuration. |

## Conventions

- PHP 8.4 or newer, `declare(strict_types=1);` as the first statement of every file, one class per
  file under its PSR-4 path, formatted by PHP-CS-Fixer and clean under PHPStan at level max.
- Standard library only unless an ADR in `docs/decisions/` justifies a dependency.
- Every public class, method, constant and property has a docblock with a summary line; invalid
  input throws a documented `\InvalidArgumentException` subclass, never returns null or a sentinel.
- Write the failing test first; tests use every literal of the acceptance items verbatim, and
  property tests (data providers over generated inputs with a fixed seed) cover invariants.
- CI-only conformance tests (external tools or downloads) carry `#[Group('conformance')]`; only CI
  runs them.
- Every behavior change updates `README.md` usage, `CHANGELOG.md` (Unreleased) and, if it is a
  design choice, a new ADR.
- Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`).

## Limits

- Never push to `main`; work on a branch and open a pull request.
- Do NOT edit `.github/`, `fabrica.json`, `docs-manifest.json`, `scripts/`, `composer.json`,
  `composer.lock`, `phpunit.xml.dist`, `phpstan.neon`, `.php-cs-fixer.dist.php`, `.editorconfig`
  or any tool configuration. They are the quality contract; changes to them are made by a human.
- Never lower the coverage threshold, add `@phpstan-ignore`, `ignoreErrors` or a baseline to
  silence a finding, or skip a test to make a gate pass.
- Never commit secrets, tokens, `auth.json` or `.env` files; gitleaks blocks them.
