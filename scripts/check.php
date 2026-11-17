<?php

declare(strict_types=1);

// Runs every quality gate of this repository, in order, stopping at the first failure.
//
// Usage: `php scripts/check.php` (the same command locally, in the factory and in CI); append gate names to run only
// those, in the standard order. Run `composer install` first.
//
// Gates: PHP-CS-Fixer (PER Coding Style, strict types) in check mode; lint: `composer validate --strict` (the lock file
// matches composer.json), `declare(strict_types=1)` in every PHP file and PHPStan at level max with the strict rules;
// semgrep (Semgrep CE with the factory's own rules in .semgrep/); tests (PHPUnit under pcov) with line coverage >= 80 %
// of src/; docs: the documentation manifest plus a docblock on every public class, method, constant and property of
// src/; secrets (gitleaks); `composer audit` over composer.lock (Packagist security advisories, any advisory fails)
// and OSV-Scanner over composer.lock (high severity). No shell: it behaves the same on Windows and Linux.
// Factory-owned file: agents must not edit it.

namespace Fabrica\Scripts;

const MIN_COVERAGE = 80.0;
const DOCS_MANIFEST = 'docs-manifest.json';
const ALLOW_MISSING_GITLEAKS = 'FABRICA_ALLOW_MISSING_GITLEAKS';
const ALLOW_OFFLINE_AUDIT = 'FABRICA_ALLOW_OFFLINE_AUDIT';
// FABRICA_CI=1 also runs the tests in the PHPUnit group "conformance" (external tools or downloads); only the CI
// conformance job sets it, so local gates and coverage skip them.
const CI_TIER = 'FABRICA_CI';
const CONFORMANCE_GROUP = 'conformance';
// The factory's own Semgrep rules; Semgrep-maintained rules are never used (license).
const SEMGREP_RULES = '.semgrep/factory-rules.yml';
const LOCK_FILE = 'composer.lock';
const HIGH_CVSS = 7.0;
// osv-scanner's exit codes: 1 when vulnerabilities were found, 128 when no package was found.
const OSV_FOUND = 1;
const OSV_NO_PACKAGES = 128;
const GATES = ['format', 'lint', 'semgrep', 'tests', 'docs', 'secrets', 'audit', 'osv'];
// The PHP sources the factory checks for strict types: the library, its tests, these scripts and root PHP files.
const PHP_DIRS = ['src', 'tests', 'scripts'];
const STRICT_HEADER = "<?php\n\ndeclare(strict_types=1);\n";

/**
 * Runs the gates named in $args (all of them when empty); returns the process exit code.
 *
 * @param list<string> $args
 */
function main(array $args): int
{
    if (!is_file('composer.json') || !is_file('scripts/check.php')) {
        fwrite(STDERR, "check: run from the repository root (composer.json not found)\n");

        return 2;
    }
    foreach ($args as $name) {
        if (!in_array($name, GATES, true)) {
            fwrite(STDERR, "check: unknown gate \"{$name}\"\n");

            return 2;
        }
    }
    foreach (GATES as $name) {
        if ([] !== $args && !in_array($name, $args, true)) {
            continue;
        }
        echo "==> {$name}\n";
        $error = run_gate($name);
        if (null !== $error) {
            fwrite(STDERR, "{$name}: {$error}\n");
            fwrite(STDERR, "==> {$name}: FAILED\n");

            return 1;
        }
        echo "==> {$name}: ok\n";
    }
    echo "==> all gates passed\n";

    return 0;
}

/**
 * One gate; null when it passes, else the reason it failed.
 */
function run_gate(string $name): ?string
{
    return match ($name) {
        'format' => format_gate(),
        'lint' => lint_gate(),
        'semgrep' => semgrep_gate(),
        'tests' => tests_gate(),
        'docs' => docs_gate(),
        'secrets' => secrets_gate(),
        'audit' => audit_gate(),
        'osv' => osv_gate(),
        default => "unknown gate {$name}",
    };
}

function flag(string $name): bool
{
    return '1' === getenv($name);
}

/**
 * The program's path when it is on PATH (with the Windows executable suffixes).
 */
function which(string $program): ?string
{
    $path = getenv('PATH');
    if (false === $path || '' === $path) {
        return null;
    }
    $suffixes = PHP_OS_FAMILY === 'Windows' ? ['.exe', '.bat', '.cmd', ''] : [''];
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
        foreach ($suffixes as $suffix) {
            $candidate = ('' === $dir ? '.' : $dir) . DIRECTORY_SEPARATOR . $program . $suffix;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    return null;
}

/**
 * Runs a program without a shell from the repository root; returns its exit code and, when asked, its stdout.
 *
 * @param list<string> $argv
 *
 * @return array{int, string}
 */
function exec_tool(array $argv, bool $capture = false): array
{
    $descriptors = [0 => STDIN, 1 => $capture ? ['pipe', 'w'] : STDOUT, 2 => STDERR];
    $pipes = [];
    $proc = proc_open($argv, $descriptors, $pipes);
    if (false === $proc) {
        return [127, ''];
    }
    $stdout = '';
    if ($capture && isset($pipes[1]) && is_resource($pipes[1])) {
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
    }

    return [proc_close($proc), $stdout];
}

/**
 * @param list<string> $argv
 */
function run(array $argv): ?string
{
    [$code] = exec_tool($argv);

    return 0 === $code ? null : sprintf('`%s` failed (exit %d)', implode(' ', $argv), $code);
}

/**
 * A tool installed by Composer into vendor/bin, run with this PHP binary; null when `composer install` has not run.
 *
 * @param list<string> $args
 *
 * @return null|list<string>
 */
function vendor_tool(string $tool, array $args): ?array
{
    $path = 'vendor/bin/' . $tool;

    return is_file($path) ? [PHP_BINARY, $path, ...$args] : null;
}

function not_installed(string $tool): string
{
    return "vendor/bin/{$tool} not found: run `composer install`";
}

function composer_path(): ?string
{
    return which('composer');
}

const NO_COMPOSER = 'composer is not installed (https://getcomposer.org/download/)';

function format_gate(): ?string
{
    $fixer = vendor_tool('php-cs-fixer', ['check', '--diff', '--show-progress=none', '--no-interaction']);
    if (null === $fixer) {
        return not_installed('php-cs-fixer');
    }
    $error = run($fixer);
    if (null !== $error) {
        return "{$error}: run `php vendor/bin/php-cs-fixer fix`";
    }
    echo "php-cs-fixer: every file follows the PER Coding Style\n";

    return null;
}

/**
 * Every PHP file of the repository's own code (vendor/ excluded), as relative paths in a stable order.
 *
 * @return list<string>
 */
function php_files(): array
{
    $root = glob('*.php');
    $files = false === $root ? [] : $root;
    foreach (PHP_DIRS as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile() && 'php' === $item->getExtension()) {
                $files[] = str_replace('\\', '/', $item->getPathname());
            }
        }
    }
    sort($files, SORT_STRING);

    return array_values(array_unique($files));
}

/**
 * The composer.json schema and its lock file, strict types everywhere, then PHPStan at level max.
 */
function lint_gate(): ?string
{
    $composer = composer_path();
    if (null === $composer) {
        return NO_COMPOSER;
    }
    $error = run([$composer, 'validate', '--strict', '--no-check-publish', '--no-interaction']);
    if (null !== $error) {
        return "{$error}: composer.json is invalid or composer.lock is out of date";
    }
    $loose = [];
    foreach (php_files() as $file) {
        $text = str_replace("\r\n", "\n", (string) file_get_contents($file));
        if (!str_starts_with($text, STRICT_HEADER)) {
            $loose[] = $file;
        }
    }
    foreach ($loose as $file) {
        fwrite(STDERR, "lint: {$file} does not start with `<?php` and `declare(strict_types=1);`\n");
    }
    if ([] !== $loose) {
        return count($loose) . ' file(s) without declare(strict_types=1)';
    }
    $phpstan = vendor_tool('phpstan', ['analyse', '--no-progress', '--no-interaction', '--memory-limit=1G']);
    if (null === $phpstan) {
        return not_installed('phpstan');
    }
    $error = run($phpstan);
    if (null !== $error) {
        return $error;
    }
    echo "composer validate, strict types and PHPStan (level max): no errors\n";

    return null;
}

function build_dir(): string
{
    $dir = getenv('FABRICA_BUILD_DIR');
    $base = false === $dir || '' === $dir ? sys_get_temp_dir() : $dir;

    return $base . DIRECTORY_SEPARATOR . 'fabrica-php-check-' . getmypid();
}

/**
 * The line coverage percentage of a Clover report (covered statements of statements), or null when it has none.
 */
function line_coverage(string $clover): ?float
{
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($clover);
    libxml_use_internal_errors($previous);
    if (false === $xml || !isset($xml->project->metrics)) {
        return null;
    }
    $metrics = $xml->project->metrics;
    $valid = (int) $metrics['statements'];
    $covered = (int) $metrics['coveredstatements'];

    return 0 === $valid ? null : 100.0 * $covered / $valid;
}

function tests_gate(): ?string
{
    $dir = build_dir();
    if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
        return "cannot create {$dir}";
    }
    $clover = $dir . DIRECTORY_SEPARATOR . 'clover.xml';
    if (is_file($clover)) {
        unlink($clover);
    }
    $args = ['--coverage-clover', $clover, '--do-not-cache-result'];
    if (flag(CI_TIER)) {
        echo "tests: CI tier, the conformance tests run too\n";
    } else {
        $args = [...$args, '--exclude-group', CONFORMANCE_GROUP];
    }
    $phpunit = vendor_tool('phpunit', $args);
    if (null === $phpunit) {
        return not_installed('phpunit');
    }
    $error = run($phpunit);
    if (null !== $error) {
        return $error;
    }
    if (!is_file($clover)) {
        return 'PHPUnit wrote no coverage report: install a coverage driver (pcov or Xdebug)';
    }
    $total = line_coverage((string) file_get_contents($clover));
    unlink($clover);
    rmdir($dir);
    if (null === $total) {
        return 'the coverage report has no lines';
    }
    printf("Total coverage: %.2f%%\n", $total);
    if ($total < MIN_COVERAGE) {
        return sprintf('total line coverage %.1f%% is below %.0f%%', $total, MIN_COVERAGE);
    }
    printf("tests: line coverage %.1f%% (minimum %.0f%%)\n", $total, MIN_COVERAGE);

    return null;
}

function count_lines(string $text): int
{
    $trimmed = rtrim(str_replace("\r\n", "\n", $text), "\n");

    return '' === $trimmed ? 0 : substr_count($trimmed, "\n") + 1;
}

/**
 * Whether some regular file under $dir matches the remaining glob segments (*, ? and [...] per segment).
 *
 * @param list<string> $segments
 */
function glob_has_file(string $dir, array $segments): bool
{
    if ([] === $segments || !is_dir($dir)) {
        return false;
    }
    $entries = scandir($dir);
    if (false === $entries) {
        return false;
    }
    $rest = array_slice($segments, 1);
    foreach ($entries as $entry) {
        if ('.' === $entry || '..' === $entry || !fnmatch($segments[0], $entry)) {
            continue;
        }
        $path = $dir . '/' . $entry;
        if ([] === $rest ? is_file($path) : glob_has_file($path, $rest)) {
            return true;
        }
    }

    return false;
}

/**
 * Every violation of docs-manifest.json; an empty list means the manifest part of the docs gate passes.
 *
 * @param array<mixed> $manifest
 *
 * @return list<string>
 */
function manifest_errors(array $manifest): array
{
    $errors = [];
    foreach (string_list($manifest['required'] ?? []) as $rel) {
        $text = is_file($rel) ? (string) file_get_contents($rel) : null;
        if (null === $text) {
            $errors[] = "missing required file: {$rel}";
        } elseif ('' === trim($text)) {
            $errors[] = "required file is empty: {$rel}";
        }
    }
    foreach (string_list($manifest['required_globs'] ?? []) as $pattern) {
        if (!glob_has_file('.', explode('/', $pattern))) {
            $errors[] = "no file matches required pattern: {$pattern}";
        }
    }
    $limits = $manifest['max_lines'] ?? [];
    foreach (is_array($limits) ? $limits : [] as $rel => $max) {
        $rel = (string) $rel;
        if (is_file($rel) && is_int($max) && count_lines((string) file_get_contents($rel)) > $max) {
            $errors[] = sprintf('%s has %d lines (max %d)', $rel, count_lines((string) file_get_contents($rel)), $max);
        }
    }
    $references = $manifest['must_reference'] ?? [];
    foreach (is_array($references) ? $references : [] as $rel => $needle) {
        $rel = (string) $rel;
        if (is_file($rel) && is_string($needle) && !str_contains((string) file_get_contents($rel), $needle)) {
            $errors[] = "{$rel} must reference {$needle}";
        }
    }

    return $errors;
}

/**
 * @return list<string>
 */
function string_list(mixed $value): array
{
    return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
}

/**
 * The fully qualified classes, interfaces, traits and enums declared in a PHP source file.
 *
 * @return list<string>
 */
function declared_types(string $code): array
{
    // Whitespace and comments dropped, so neighbours are the previous and next significant tokens.
    $tokens = array_values(array_filter(\PhpToken::tokenize($code), static fn(\PhpToken $t): bool => !$t->isIgnorable()));
    $namespace = '';
    $types = [];
    foreach ($tokens as $i => $token) {
        if ($token->is(T_NAMESPACE)) {
            $name = $tokens[$i + 1] ?? null;
            $namespace = null !== $name && $name->is([T_NAME_QUALIFIED, T_STRING]) ? $name->text : '';
        } elseif ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
            $previous = $tokens[$i - 1] ?? null;
            $name = $tokens[$i + 1] ?? null;
            // Foo::class and anonymous classes (new class) declare nothing.
            if (null !== $previous && $previous->is([T_DOUBLE_COLON, T_NEW])) {
                continue;
            }
            if (null !== $name && $name->is(T_STRING)) {
                $types[] = ('' === $namespace ? '' : $namespace . '\\') . $name->text;
            }
        }
    }

    return $types;
}

/**
 * Whether a docblock has a summary line (text before any tag).
 */
function has_summary(false|string $doc): bool
{
    if (false === $doc) {
        return false;
    }
    foreach (explode("\n", $doc) as $line) {
        $line = trim(trim($line), '/* ');
        if ('' === $line) {
            continue;
        }

        return !str_starts_with($line, '@');
    }

    return false;
}

/**
 * Every public class, method, constant, enum case and property of src/ without a docblock summary.
 *
 * @return list<string>
 */
function api_doc_errors(): array
{
    if (!is_file('vendor/autoload.php')) {
        return ['vendor/autoload.php not found: run `composer install`'];
    }
    require_once 'vendor/autoload.php';
    $errors = [];
    $files = array_values(array_filter(php_files(), static fn(string $f): bool => str_starts_with($f, 'src/')));
    foreach ($files as $file) {
        foreach (declared_types((string) file_get_contents($file)) as $type) {
            if (!class_exists($type) && !interface_exists($type) && !trait_exists($type) && !enum_exists($type)) {
                $errors[] = "{$file}: {$type} is not autoloadable (PSR-4: the namespace must match the path)";

                continue;
            }
            $class = new \ReflectionClass($type);
            if (!has_summary($class->getDocComment())) {
                $errors[] = "{$file}: {$type} has no docblock";
            }
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() === $type && !$method->isInternal()
                    && !has_summary($method->getDocComment())) {
                    $errors[] = "{$file}: {$type}::{$method->getName()}() has no docblock";
                }
            }
            foreach ($class->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
                if ($constant->getDeclaringClass()->getName() === $type && !has_summary($constant->getDocComment())) {
                    $errors[] = "{$file}: {$type}::{$constant->getName()} has no docblock";
                }
            }
            foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() === $type && !$property->isPromoted()
                    && !has_summary($property->getDocComment())) {
                    $errors[] = "{$file}: {$type}::\${$property->getName()} has no docblock";
                }
            }
        }
    }

    return $errors;
}

function docs_gate(): ?string
{
    $raw = is_file(DOCS_MANIFEST) ? (string) file_get_contents(DOCS_MANIFEST) : null;
    if (null === $raw) {
        return DOCS_MANIFEST . ' not found';
    }

    try {
        $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        return DOCS_MANIFEST . ' is not valid JSON: ' . $e->getMessage();
    }
    $errors = [...manifest_errors(is_array($manifest) ? $manifest : []), ...api_doc_errors()];
    foreach ($errors as $error) {
        fwrite(STDERR, "docs: {$error}\n");
    }
    if ([] !== $errors) {
        return count($errors) . ' documentation problem(s)';
    }
    echo 'docs: every file in ' . DOCS_MANIFEST . " is present and the public API is documented\n";

    return null;
}

function secrets_gate(): ?string
{
    $exe = which('gitleaks');
    if (null === $exe) {
        if (flag(ALLOW_MISSING_GITLEAKS)) {
            echo "gitleaks: SKIPPED (not installed)\n";

            return null;
        }

        return 'FAIL - gitleaks is not installed. Install it (https://github.com/gitleaks/gitleaks#installing), e.g. '
            . '`winget install Gitleaks.Gitleaks`, `brew install gitleaks` or a release binary, or set '
            . ALLOW_MISSING_GITLEAKS . '=1 to skip this gate locally.';
    }

    return run([$exe, 'dir', '.', '--redact', '--verbose', '--no-banner']);
}

/**
 * A scanner that is not installed: skipped locally, a failure in CI (FABRICA_CI=1).
 */
function missing_scanner(string $tool): ?string
{
    if (flag(CI_TIER)) {
        return "{$tool} not installed (required when " . CI_TIER . '=1)';
    }
    echo "{$tool}: skipped: {$tool} not installed\n";

    return null;
}

/**
 * Semgrep CE with the factory's own rules only; ERROR rules fail and nosemgrep comments are ignored. Tests, scripts/
 * and vendor/ are not scanned.
 */
function semgrep_gate(): ?string
{
    $exe = which('semgrep');
    if (null === $exe) {
        return missing_scanner('semgrep');
    }

    return run([
        $exe,
        'scan',
        '--config',
        SEMGREP_RULES,
        '--error',
        '--severity',
        'ERROR',
        '--metrics',
        'off',
        '--disable-version-check',
        '--disable-nosem',
        '--quiet',
        '--exclude=scripts',
        '--exclude=tests',
        '--exclude=vendor',
        '.',
    ]);
}

/**
 * The array under $key of a decoded JSON object, or an empty array.
 *
 * @param array<mixed> $object
 *
 * @return array<mixed>
 */
function items(array $object, string $key): array
{
    $value = $object[$key] ?? [];

    return is_array($value) ? $value : [];
}

function text(mixed $object, string $key): string
{
    if (!is_array($object)) {
        return '';
    }
    $value = $object[$key] ?? '';

    return is_scalar($value) ? (string) $value : '';
}

/**
 * Every package of composer.lock (library and dev) against the Packagist security advisories; any advisory fails,
 * like pip-audit or npm audit. Needs the network; FABRICA_ALLOW_OFFLINE_AUDIT=1 skips it when Packagist is
 * unreachable. Abandoned packages are reported by Composer elsewhere, not here.
 */
function audit_gate(): ?string
{
    $composer = composer_path();
    if (null === $composer) {
        return NO_COMPOSER;
    }
    $argv = [$composer, 'audit', '--locked', '--format=json', '--abandoned=ignore', '--no-interaction'];
    [$code, $stdout] = exec_tool($argv, true);
    $report = json_decode($stdout, true);
    if (!is_array($report)) {
        if (flag(ALLOW_OFFLINE_AUDIT)) {
            echo "audit: SKIPPED (the Packagist advisory feed is unreachable)\n";

            return null;
        }

        return "`composer audit` failed (exit {$code}), or set " . ALLOW_OFFLINE_AUDIT . '=1 to skip this gate offline';
    }
    $found = [];
    foreach (items($report, 'advisories') as $package => $advisories) {
        foreach (is_array($advisories) ? $advisories : [] as $advisory) {
            $id = '' !== text($advisory, 'cve') ? text($advisory, 'cve') : text($advisory, 'advisoryId');
            $found[] = sprintf('%s %s %s %s', $package, $id, text($advisory, 'severity'), text($advisory, 'link'));
        }
    }
    foreach ($found as $line) {
        fwrite(STDERR, "audit: {$line}\n");
    }
    if ([] !== $found) {
        return count($found) . ' known vulnerabilities in the locked packages';
    }
    if (0 !== $code) {
        return "`composer audit` failed (exit {$code})";
    }
    echo "audit: no known vulnerabilities in the locked Composer packages\n";

    return null;
}

/**
 * The advisory groups with CVSS >= 7.0 or no score (unknown counts as high).
 *
 * @param array<mixed> $report
 *
 * @return list<string>
 */
function osv_blocking(array $report): array
{
    $blocking = [];
    foreach (items($report, 'results') as $result) {
        foreach (items(is_array($result) ? $result : [], 'packages') as $package) {
            $package = is_array($package) ? $package : [];
            $info = $package['package'] ?? [];
            foreach (items($package, 'groups') as $group) {
                $severity = trim(text($group, 'max_severity'));
                $known = is_numeric($severity);
                if (!$known || (float) $severity >= HIGH_CVSS) {
                    $ids = implode(',', string_list(is_array($group) ? ($group['ids'] ?? []) : []));
                    $blocking[] = sprintf(
                        '%s@%s %s (%s)',
                        text($info, 'name'),
                        text($info, 'version'),
                        $ids,
                        $known ? $severity : 'unknown',
                    );
                }
            }
        }
    }

    return $blocking;
}

/**
 * OSV-Scanner over composer.lock; high or unknown severity fails.
 */
function osv_gate(): ?string
{
    $exe = which('osv-scanner');
    if (null === $exe) {
        return missing_scanner('osv-scanner');
    }
    [$code, $stdout] = exec_tool([$exe, 'scan', 'source', '--format', 'json', '--lockfile=' . LOCK_FILE], true);
    if (0 === $code) {
        echo 'osv-scanner: no known vulnerabilities in ' . LOCK_FILE . "\n";

        return null;
    }
    if (OSV_NO_PACKAGES === $code) {
        echo 'osv-scanner: no packages in ' . LOCK_FILE . "\n";

        return null;
    }
    if (OSV_FOUND !== $code) {
        return "osv-scanner failed (exit {$code})";
    }
    $report = json_decode($stdout, true);
    if (!is_array($report)) {
        return 'osv-scanner: unreadable JSON report';
    }
    $blocking = osv_blocking($report);
    foreach ($blocking as $line) {
        fwrite(STDERR, "osv-scanner: {$line}\n");
    }
    if ([] !== $blocking) {
        return count($blocking) . ' advisory group(s) of high or unknown severity';
    }
    echo "osv-scanner: only advisories below high severity (reported, not failing)\n";

    return null;
}

exit(main(array_values(array_slice($argv, 1))));
