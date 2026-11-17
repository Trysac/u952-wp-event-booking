# Stack

## Used

| Tool | Why |
|---|---|
| PHP 8.4 | Security support until 2028-12-31; `declare(strict_types=1)` in every file, typed constants. |
| Composer 2 | The standard package manager; `composer.lock` committed, plugins disabled, PSR-4 autoloading. |
| PHP-CS-Fixer | The PER Coding Style (PSR-12's successor) plus strict types and strict comparisons. |
| PHPStan (level max) + strict rules | Type and logic errors at the strictest level, with the PHPUnit extension. |
| PHPUnit + pcov | Tests, data-provider property tests and line coverage (Clover report read by the gate). |
| Semgrep CE + OSV-Scanner | The factory's own rules; OSV advisories over `composer.lock`. |
| `composer audit` | Packagist security advisories for every locked package. |
| gitleaks | Secret scanning locally and in CI. |
| GitHub Actions + Dependabot | Thin CI wrapper over the local gates; weekly dependency updates. |

## Discarded

| Tool | Why not |
|---|---|
| Psalm | PHPStan at level max with the strict rules covers the same ground; one analyser is enough. |
| PHP_CodeSniffer | PHP-CS-Fixer both checks and fixes the PER Coding Style. |
| Eris (property testing) | Seeded data providers give reproducible generated cases without another dependency. |
| phpDocumentor | The docs gate checks the docblock of every public symbol directly with Reflection. |
| A `version` in composer.json | Packagist reads the version from the release tag. |
| Shell scripts for gates | Not portable to Windows; the gates are a PHP script in `scripts/check.php`. |
