<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->append([
        __FILE__,
    ]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS3x0' => true,
        'array_syntax' => ['syntax' => 'short'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
