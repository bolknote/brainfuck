<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$finder = PhpCsFixer\Finder::create()
    ->in($root)
    ->exclude('vendor')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
