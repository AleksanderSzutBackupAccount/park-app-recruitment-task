<?php

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
    ])
    ->setCacheFile('.cache/.php-cs-fixer')
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(['src', 'tests'])
    );

