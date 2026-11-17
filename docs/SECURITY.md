# Security notes

## Threat model

`trysac/u952-wp-event-booking` is a pure library without I/O. The main risks are:

- **Supply chain**: a compromised Composer package, plugin or CI action. Mitigation: standard
  library only, every package pinned by the committed `composer.lock`, Composer plugins disabled
  (`allow-plugins: false`), `composer audit` (Packagist security advisories) and OSV-Scanner over
  `composer.lock` in the gates, Dependabot weekly updates, actions pinned by commit SHA, minimal
  workflow permissions.
- **Leaked secrets**: tokens committed by mistake. Mitigation: `gitleaks` runs in the local gates
  and in CI; `.env` and `auth.json` files are never committed.
- **Insecure code**: code evaluation, command injection, object injection through
  `unserialize()`, disabled TLS verification. Mitigation: PHPStan at level max with the strict
  rules, `declare(strict_types=1)` everywhere, and the factory's Semgrep rules
  (`.semgrep/factory-rules.yml`, `nosemgrep` ignored); fix the code instead of silencing it.
- **Untrusted input**: callers may pass hostile strings. Mitigation: validate input and throw
  explicit, documented exceptions; never `unserialize()` untrusted data; never build a shell
  command line from data.

## Publishing

Releases are git tags; publishing on Packagist needs an account linked to this repository, whose
credentials are never stored in the repository.

To report a vulnerability, see [SECURITY.md](../SECURITY.md).
