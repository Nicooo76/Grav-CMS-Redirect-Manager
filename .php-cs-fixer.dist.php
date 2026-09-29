<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/classes', __DIR__ . '/cli', __DIR__ . '/tests'])
    ->append([__DIR__ . '/redirect-manager.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'array_syntax' => ['syntax' => 'short'],
    ])
    ->setFinder($finder);
