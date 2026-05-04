# Brainfuck → PHP Compiler

A Brainfuck compiler/transpiler written in PHP. It translates BF source code into
optimised PHP and either executes it directly or returns the generated string for
further use.

## Requirements

- PHP 8.4.1 or later
- Composer

## Installation

```bash
composer install
```

## Usage

### CLI

```bash
php run.php samples/prog/hello_world.b
# Brainfork (`Y` opcode): same flags as many interpreters (e.g. weave.rb `-Y`)
php run.php -Y fork_example.bf
```

### API

```php
require 'vendor/autoload.php';

use BolkNote\Brainfuck\Compiler;

// Standard 8-bit BF (default) — cells wrap at 256
$compiler = new Compiler();          // same as new Compiler(8)

// Brainfork: enable opcode `Y` (fork); otherwise `Y` is stripped like junk
$fork = new Compiler(brainfork: true);

// Compile to a PHP string and execute immediately
eval($compiler->compile(file_get_contents('program.bf')));

// Compile with pre-filled input (no stdin required)
eval($compiler->compile(',+.', 'A'));   // outputs "B"

// Get just the PHP body (without the tape header)
$body = $compiler->toPHP('++++++++++.');
```

### Cell width

The `$cellBits` constructor parameter controls cell arithmetic overflow behaviour:

| `$cellBits` | Cell range | Overflow behaviour | Use case |
|-------------|------------|-------------------|----------|
| `8` (default) | 0 – 255 | wraps modulo 256 | standard BF programs |
| `16` | 0 – 65535 | wraps modulo 65536 | programs designed for 16-bit cells (e.g. `PI16.BF`) |
| `0` | 0 – PHP_INT_MAX | wraps modulo PHP_INT_MAX+1 | programs needing very large cell values |

```php
// 8-bit (standard): cell wraps 0→255 and 255→0
$c8  = new Compiler(8);
eval($c8->compile(str_repeat('-', 157) . '.'));   // prints chr(99)

// 16-bit: programs that rely on 16-bit arithmetic overflow
$c16 = new Compiler(16);
eval($c16->compile(file_get_contents('samples/prog/PI16.BF')));

// Wrap at PHP_INT_MAX: very large cell values, still unsigned
$c0  = new Compiler(0);
```

Programs like `beer.b`, `bockbeer.b`, `99botles.bf` and `ryan-beer.bf` initialise
their counter via 8-bit overflow (`0 − 157 = 99` in 8-bit BF) and require
`Compiler(8)` (the default) to run correctly.

## Extension opcodes

Beyond standard BF (`+ - < > [ ] . ,`), the compiler recognises:

| Opcode | Meaning |
|--------|---------|
| `#` | Debug: print current cell index and value (`$i: $d[$i]`) |
| `Y` | Optional ([Brainfork](https://esolangs.org/wiki/Brainfork)): fork via `pcntl_fork()` — parent zeroes the current cell; child moves the pointer right and sets that cell to `1`. |

`#` is always accepted. `Y` is accepted only when Brainfork is enabled (`new Compiler(..., brainfork: true)` or CLI `-Y` / `--fork` / `--brainfork`); otherwise `Y` is removed like any non-BF character (same idea as plain BF ignoring unknown symbols).

Requires the PHP **pcntl** extension when compiling or running programs that use `Y`.

## Optimisations

The compiler applies several passes before generating PHP:

1. **Dead loop elimination** — a loop at the very start of the program is removed
   because the tape cell is always 0 there.
2. **Run-length encoding** — consecutive identical `+`, `-`, `<`, `>` instructions
   are collapsed into a single add/subtract/move with a constant operand
   (e.g. `+++++` → `$d[$i]+=5`).
3. **Opposing-run cancellation** — interleaved `+`/`-` or `>`/`<` runs are reduced
   to their net effect before encoding.
4. **Loop body optimisation** — simple loops of the form `[->`*n*`+<]` (multiply-move)
   are replaced with direct cell arithmetic, eliminating the `while` entirely.
5. **Relative addressing** — patterns like `>+++<` become a single
   `$d[$i+1]+=3` without moving the pointer.

## Running tests

```bash
composer test
# or directly:
vendor/bin/phpunit --configuration=config/phpunit.xml
```

## Project structure

```
config/           — PHPStan, PHPUnit, PHP-CS-Fixer, Rector
src/
  Compiler.php    — BF → PHP compiler class
tests/
  CompilerTest.php
samples/
  prog/           — classic BF programs (hello world, mandelbrot, …)
  quine/          — BF quine collection
run.php           — CLI entry point
```
