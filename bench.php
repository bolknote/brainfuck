#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BolkNote\Brainfuck\Compiler;

/**
 * Benchmark: scan-loop optimisation
 *
 * Measures two things for each program:
 *   1. Code quality  — number of `while(` / `for(` in generated PHP.
 *   2. Execution time — median over N runs (skipped for slow programs).
 *
 * Run before and after implementing the optimisation to compare.
 */

$programs = [
    [
        'label'  => 'hanoi.bf',
        'path'   => 'samples/programs/demos/hanoi.bf',
        'input'  => '',
        'runs'   => 0,   // too slow for timing (~5s), measure code metrics only
    ],
    [
        'label'  => '2d_table.bf',
        'path'   => 'samples/programs/tests/2d_table.bf',
        'input'  => '',
        'runs'   => 0,   // zero-input program, measure code metrics only
    ],
    [
        'label'  => 'hello_world.bf',
        'path'   => 'samples/programs/hello/hello_world.bf',
        'input'  => '',
        'runs'   => 500,
    ],
];

$compiler = new Compiler();

$sep = str_repeat('-', 80);
echo $sep . "\n";
printf("%-22s  %6s  %5s  %5s  %8s\n", 'Program', 'compile', 'while', 'for(;', 'exec(ms)');
echo $sep . "\n";

foreach ($programs as $prog) {
    $source = file_get_contents(__DIR__ . '/' . $prog['path']);
    if ($source === false) {
        printf("%-22s  ERROR: cannot read file\n", $prog['label']);
        continue;
    }

    // Compile
    $t0      = hrtime(true);
    $code    = $compiler->compile($source, $prog['input']);
    $compMs  = round((hrtime(true) - $t0) / 1e6, 2);

    // Code quality metrics
    $whiles = substr_count($code, 'while(');
    $fors   = substr_count($code, 'for(;');

    // Execution timing
    $execStr = '      -';
    if ($prog['runs'] > 0) {
        $times = [];
        for ($i = 0; $i < $prog['runs']; $i++) {
            ob_start();
            $t0 = hrtime(true);
            eval($code);
            $elapsed = (hrtime(true) - $t0) / 1e6;
            ob_end_clean();
            $times[] = $elapsed;
        }
        sort($times);
        $mid     = (int) floor(count($times) / 2);
        $median  = $times[$mid];
        $execStr = sprintf('%8.3f', $median);
    }

    printf(
        "%-22s  %5.2fms  %5d  %5d  %s\n",
        $prog['label'],
        $compMs,
        $whiles,
        $fors,
        $execStr,
    );
}

echo $sep . "\n";
echo "Columns: compile=compilation time, while=while() loops, for(;=for(; loops, exec=median exec time\n";
