<?php

declare(strict_types=1);

// Factory-owned file: agents must not edit it. PHP-CS-Fixer applies the PER Coding Style (PSR-12's successor) plus
// strict types in every file; `php vendor/bin/php-cs-fixer fix` rewrites, the format gate only checks.

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/scripts'])
    ->append([__FILE__]);

return new Config()
    ->setRiskyAllowed(true)
    ->setUsingCache(false)
    ->setParallelConfig(ParallelConfigFactory::sequential())
    ->setRules([
        '@PER-CS' => true,
        '@PER-CS:risky' => true,
        '@PHP8x4Migration' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_trim' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
    ])
    ->setFinder($finder);
