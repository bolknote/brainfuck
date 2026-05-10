<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cacheDir = $root . '/cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$finder = PhpCsFixer\Finder::create()
    ->in($root)
    ->exclude('vendor')
    ->exclude('cache')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setCacheFile($cacheDir . '/php-cs-fixer.cache')
    ->setRiskyAllowed(true)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
