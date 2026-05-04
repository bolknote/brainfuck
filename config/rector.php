<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

$root = dirname(__DIR__);

return RectorConfig::configure()
    ->withPaths([
        $root . '/src',
        $root . '/tests',
        $root . '/run.php',
    ])
    ->withPhpSets(php84: true);
