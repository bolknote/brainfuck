#!/usr/bin/env php
<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/vendor/autoload.php';

use BolkNote\Brainfuck\Compiler;

// Parse --bits=N option (default: 8)
$cellBits = 8;
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--bits=(\d+)$/', $arg, $m)) {
        $cellBits = (int) $m[1];
    } else {
        $args[] = $arg;
    }
}

if (empty($args[0])) {
    fwrite(STDERR, "Usage: php run.php [--bits=8|16|0] <file.bf>\n");
    exit(1);
}

$source = file_get_contents($args[0]);
if ($source === false) {
    fwrite(STDERR, "Error: cannot read file '{$args[0]}'\n");
    exit(1);
}

$compiler = new Compiler($cellBits);
$code = $compiler->compile($source);

eval($code);
