<?php

// Coding style: PER Coding Style (the successor of PSR-2 / PSR-12), plus a few extra rules.
// Run `composer lint` to check and `composer fix` to fix.

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/demo', __DIR__ . '/demo-multilang']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS' => true,
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'no_extra_blank_lines' => true,
        'no_whitespace_in_blank_line' => true,
    ])
    ->setFinder($finder);
