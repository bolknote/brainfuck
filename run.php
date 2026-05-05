#!/usr/bin/env php
<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/vendor/autoload.php';

use BolkNote\Brainfuck\Compiler;

$cellBits     = Compiler::DEFAULT_CELL_BITS;
$brainfork    = false;
$debug        = false;
$randomOpcode = false;
$inputCrLf    = false;
$args         = [];
$rawArgv   = $_SERVER['argv'] ?? null;
$cliArgv   = is_array($rawArgv) ? $rawArgv : [];
foreach (array_slice($cliArgv, 1) as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (preg_match('/^--bits=(\d+)$/', $arg, $m)) {
        $cellBits = (int) $m[1];
    } elseif ($arg === '-Y' || $arg === '--fork' || $arg === '--brainfork') {
        $brainfork = true;
    } elseif ($arg === '-d' || $arg === '--debug') {
        $debug = true;
    } elseif ($arg === '--random' || $arg === '-@') {
        $randomOpcode = true;
    } elseif ($arg === '--crlf-input' || $arg === '-W') {
        $inputCrLf = true;
    } else {
        $args[] = $arg;
    }
}

if ($args === [] || $args[0] === '') {
    fwrite(STDERR, "Usage: php run.php [--bits=8|16|0] [-Y|--fork] [-d|--debug] [--random|-@] [--crlf-input|-W] <file.bf>\n");
    exit(1);
}

$sourcePath = $args[0];
$source = file_get_contents($sourcePath);
if ($source === false) {
    fwrite(STDERR, "Error: cannot read file '{$sourcePath}'\n");
    exit(1);
}

$compiler = new Compiler($cellBits, $brainfork, $debug, $randomOpcode, $inputCrLf);
$code = $compiler->compile($source);

eval($code);
