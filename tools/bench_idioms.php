#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use BolkNote\Brainfuck\Compiler;

/**
 * @return array<string, array{pattern: string, files: list<string>}>
 */
function idiomBenchmarks(): array
{
    return [
        'move-right' => [
            'pattern' => '[>+<-]',
            'files' => [
                'samples/collections/fabianishere/interpreter/bfbf.bf',
                'samples/programs/experiments/oobrain.bf',
                'samples/programs/tests/cell_size_4.bf',
            ],
        ],
        'move-left' => [
            'pattern' => '[<+>-]',
            'files' => [
                'samples/collections/fabianishere/interpreter/bfbf.bf',
                'samples/collections/esoteric_archive/lib/function.bf',
                'samples/collections/esoteric_archive/lib/math.bf',
            ],
        ],
        'add-left' => [
            'pattern' => '[<+>-]<',
            'files' => [
                'samples/collections/fabianishere/interpreter/bfbf.bf',
                'samples/programs/experiments/oobrain.bf',
                'samples/programs/demos/bockbeer.bf',
            ],
        ],
        'scatter' => [
            'pattern' => '[>+>+<<-]',
            'files' => [
                'samples/programs/experiments/oobrain.bf',
                'samples/collections/fabianishere/asciiart/asciiart.bf',
                'samples/programs/math/prime.bf',
            ],
        ],
        'restore' => [
            'pattern' => '[<<+>>-]',
            'files' => [
                'samples/programs/experiments/oobrain.bf',
                'samples/programs/math/prime.bf',
                'samples/collections/esoteric_archive/src_bf/prime.bf',
            ],
        ],
        'dup' => [
            'pattern' => '[>+>+<<-]>>[<<+>>-]',
            'files' => [
                'samples/programs/experiments/oobrain.bf',
                'samples/programs/math/prime.bf',
                'samples/collections/fabianishere/math/prime.bf',
            ],
        ],
        'clear-merge' => [
            'pattern' => '[>+>[-]<<-]',
            'files' => [
                'samples/programs/experiments/oobrain.bf',
                'samples/collections/fabianishere/lost_kingdom.bf',
                'samples/programs/tests/cell_size_4.bf',
            ],
        ],
        'if-prefix' => [
            'pattern' => '>[-]<[>[-]+<-]>',
            'files' => [
                'samples/programs/experiments/oobrain.bf',
                'samples/collections/urban_mueller/programs/varia.bf',
                'samples/collections/esoteric_archive/lib/math.bf',
            ],
        ],
        'one-shot' => [
            'pattern' => '[>+<[-]]',
            'files' => [
                'samples/programs/demos/beer.bf',
            ],
        ],
        'swap' => [
            'pattern' => '[>+<-]<[>+<-]>>[<<+>>-]<',
            'files' => [
                'samples/collections/urban_mueller/programs/varia.bf',
                'samples/collections/esoteric_archive/src_bf/varia.bf',
                'samples/collections/esoteric_archive/lib/math.bf',
            ],
        ],
    ];
}

function normaliseBrainfuck(string $source): string
{
    return preg_replace('/[^+\-<>\[\],.#Y@]/', '', $source) ?? '';
}

function readFileOrFail(string $path): string
{
    $source = file_get_contents($path);
    if (false === $source) {
        throw new RuntimeException('Cannot read '.$path);
    }

    return $source;
}

/**
 * @return array<string, array{string, int, int}>
 */
function runtimeBenchmarks(): array
{
    return [
        '8bit move 20k blocks' => [
            str_repeat('+++++[>+<-]>[-]<', 20_000),
            5,
            Compiler::CELL_BITS_8,
        ],
        '8bit scatter 10k blocks' => [
            str_repeat('+++++[>+>+<<-]>>[-]<<>[-]<', 10_000),
            5,
            Compiler::CELL_BITS_8,
        ],
        '8bit dup 8k blocks' => [
            str_repeat('+++++[>+>+<<-]>>[<<+>>-]<<[-]>>[-]<<', 8_000),
            5,
            Compiler::CELL_BITS_8,
        ],
        '8bit clearmerge 10k blocks' => [
            str_repeat('+++++>>+++<<[>+>[-]<<-]>[-]<', 10_000),
            5,
            Compiler::CELL_BITS_8,
        ],
        '8bit one-shot 5k blocks' => [
            str_repeat('+++++[>+<[-]]>[-]<', 5_000),
            5,
            Compiler::CELL_BITS_8,
        ],
        '8bit nested-if 5k blocks' => [
            str_repeat('+++++>+++<[>[->+<]<[-]]>>[-]<<', 5_000),
            5,
            Compiler::CELL_BITS_8,
        ],
        'unbounded move 200k' => [
            str_repeat('+', 200_000).'[>+<-].',
            5,
            Compiler::CELL_BITS_UNBOUNDED,
        ],
        'unbounded scatter 150k' => [
            str_repeat('+', 150_000).'[>+>+<<-]>>.',
            5,
            Compiler::CELL_BITS_UNBOUNDED,
        ],
        'unbounded clearmerge 120k' => [
            str_repeat('+', 120_000).'>>+++<<[>+>[-]<<-].',
            5,
            Compiler::CELL_BITS_UNBOUNDED,
        ],
    ];
}

function loadHeadCompiler(): bool
{
    $source = shell_exec('git show HEAD:src/Compiler.php 2>/dev/null');
    if (!is_string($source) || '' === $source) {
        return false;
    }

    $source = preg_replace('/^<\?php\s*/', '', $source) ?? '';
    $source = str_replace('namespace BolkNote\\Brainfuck;', 'namespace Baseline\\Brainfuck;', $source);
    eval($source);

    return class_exists('Baseline\\Brainfuck\\Compiler');
}

function runCompiled(string $code, int $runs): float
{
    $fn = eval($code);
    if (!is_callable($fn)) {
        throw new RuntimeException('Compiled code did not return a callable');
    }

    ob_start();
    $fn();
    ob_end_clean();

    $start = hrtime(true);
    for ($i = 0; $i < $runs; ++$i) {
        ob_start();
        $fn();
        ob_end_clean();
    }

    return (hrtime(true) - $start) / 1_000_000;
}

function compileWith(object $compiler, string $source): string
{
    if (!method_exists($compiler, 'compile')) {
        throw new RuntimeException('Compiler object has no compile method');
    }

    $code = $compiler->compile($source);
    if (!is_string($code)) {
        throw new RuntimeException('Compiler returned non-string code');
    }

    return $code;
}

$root = dirname(__DIR__);
$compiler = new Compiler();
$iterations = 5;

printf("%-12s %-58s %5s %8s %8s %10s\n", 'idiom', 'file', 'hits', 'bytes', 'while', 'compile_ms');
printf("%'-105s\n", '');

foreach (idiomBenchmarks() as $idiom => $config) {
    foreach ($config['files'] as $relativePath) {
        $path = $root.'/'.$relativePath;
        $source = readFileOrFail($path);
        $normalised = normaliseBrainfuck($source);
        $hits = substr_count($normalised, $config['pattern']);

        $elapsedNs = 0;
        $body = '';
        for ($i = 0; $i < $iterations; ++$i) {
            $start = hrtime(true);
            $body = $compiler->toPHP($source);
            $elapsedNs += hrtime(true) - $start;
        }

        printf(
            "%-12s %-58s %5d %8d %8d %10.3f\n",
            $idiom,
            $relativePath,
            $hits,
            strlen($body),
            substr_count($body, 'while('),
            ($elapsedNs / $iterations) / 1_000_000,
        );
    }
}

echo "\nRuntime benchmark: HEAD baseline vs current working tree\n";
if (!loadHeadCompiler()) {
    echo "Cannot load HEAD baseline compiler; skipping runtime comparison.\n";
    exit(0);
}

printf(
    "%-28s %10s %10s %9s %8s %8s %9s %9s\n",
    'case',
    'old_ms',
    'new_ms',
    'speedup',
    'old_wh',
    'new_wh',
    'old_KB',
    'new_KB',
);
printf("%'-107s\n", '');

foreach (runtimeBenchmarks() as $name => [$source, $runs, $cellBits]) {
    $oldCompiler = new Baseline\Brainfuck\Compiler($cellBits);
    $newCompiler = new Compiler($cellBits);

    $oldCode = compileWith($oldCompiler, $source);
    $newCode = $newCompiler->compile($source);

    $oldMs = runCompiled($oldCode, $runs);
    $newMs = runCompiled($newCode, $runs);

    printf(
        "%-28s %10.3f %10.3f %8.2fx %8d %8d %9.1f %9.1f\n",
        $name,
        $oldMs,
        $newMs,
        $oldMs / max($newMs, 0.000001),
        substr_count($oldCode, 'while('),
        substr_count($newCode, 'while('),
        strlen($oldCode) / 1024,
        strlen($newCode) / 1024,
    );
}
