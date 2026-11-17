<?php

declare(strict_types=1);

// Adds one Composer dependency for the factory: `php scripts/add-dependency.php [--dev] <vendor/package> [<spec>]`.
//
// The spec is the allowed range of the item (empty, an exact version, `==x`, `>=x` or `>=x,<y`); Composer writes it to
// composer.json as given (a library states ranges, its consumers resolve them) and pins the newest stable release
// inside it in composer.lock. Empty means Composer's own choice, `^` of the newest stable release. `--dev` goes to
// require-dev (tests only). Composer reverts composer.json when the install fails. No shell: it runs Composer with
// an argument list. Factory-owned file: agents must not edit it.

namespace Fabrica\Scripts;

// Composer's package name rule (vendor/package, lower case).
const PACKAGE_NAME = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#';

/**
 * @param list<string> $args
 */
function add_dependency(array $args): int
{
    $dev = false;
    $positional = [];
    foreach ($args as $arg) {
        if ('--dev' === $arg) {
            $dev = true;
        } elseif ('--git' === $arg) {
            fwrite(STDERR, "add-dependency: factory repositories are not installable in PHP yet (no Packagist release)\n");

            return 1;
        } elseif (str_starts_with($arg, '--')) {
            fwrite(STDERR, "add-dependency: unknown option {$arg}\n");

            return 2;
        } else {
            $positional[] = $arg;
        }
    }
    if (1 !== count($positional) && 2 !== count($positional)) {
        fwrite(STDERR, "usage: php scripts/add-dependency.php [--dev] <vendor/package> [<spec>]\n");

        return 2;
    }
    $name = $positional[0];
    $spec = trim($positional[1] ?? '');
    if (1 !== preg_match(PACKAGE_NAME, $name)) {
        fwrite(STDERR, "add-dependency: {$name} is not a Composer package name (vendor/package)\n");

        return 2;
    }
    if (str_starts_with($spec, '==')) {
        $spec = ltrim(substr($spec, 2));
    }
    $composer = find_composer();
    if (null === $composer) {
        fwrite(STDERR, "add-dependency: composer is not installed (https://getcomposer.org/download/)\n");

        return 1;
    }
    $argv = [$composer, 'require', '--no-interaction', '--no-progress', '--update-with-dependencies'];
    if ($dev) {
        $argv[] = '--dev';
    }
    $argv[] = '' === $spec ? $name : "{$name}:{$spec}";
    $pipes = [];
    $proc = proc_open($argv, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    if (false === $proc) {
        fwrite(STDERR, "add-dependency: cannot start composer\n");

        return 1;
    }
    $code = proc_close($proc);
    if (0 !== $code) {
        fwrite(STDERR, "add-dependency: `composer require` failed (exit {$code})\n");

        return 1;
    }

    return 0;
}

function find_composer(): ?string
{
    $path = getenv('PATH');
    if (false === $path || '' === $path) {
        return null;
    }
    $suffixes = PHP_OS_FAMILY === 'Windows' ? ['.bat', '.exe', '.cmd', ''] : [''];
    foreach (explode(PATH_SEPARATOR, $path) as $dir) {
        foreach ($suffixes as $suffix) {
            $candidate = ('' === $dir ? '.' : $dir) . DIRECTORY_SEPARATOR . 'composer' . $suffix;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }

    return null;
}

exit(add_dependency(array_values(array_slice($argv, 1))));
