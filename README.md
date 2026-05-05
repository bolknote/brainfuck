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
bfrun samples/programs/hello/hello_world.bf
# Brainfork (`Y` opcode): same flags as many interpreters (e.g. weave.rb `-Y`)
bfrun -Y fork_example.bf
```

#### CLI options

`bfrun` supports the following CLI options:

- `--bits=8|16|0` — cell width mode (`8` default, `16` for 16-bit overflow semantics, `0` for very large values).
- `-Y` — enable Brainfork opcode `Y`.
- `--fork` — same as `-Y`.
- `--brainfork` — same as `-Y`.
- `-d` — enable debug output for `#` opcode handling.
- `--debug` — same as `-d`.
- `-@` — enable the non-standard `@` opcode (`random_int()` into the current cell).
- `--random` — same as `-@`.
- `-W` — normalise input for BF programs that expect Windows line endings: lone `\n` bytes become `\r\n`.
- `--crlf-input` — same as `-W`.
- `--immediate-stdin` / `-I` — read stdin one byte at a time for `,`; on Unix-like systems, when stdin is an interactive TTY, `bfrun` temporarily switches it to raw, nonblocking mode and restores it on exit. When no byte is ready, `,` reads `0`, which lets interactive programs poll the keyboard while continuing to render. On Windows and non-TTY stdin, this still reads byte-by-byte from PHP's stream, but does not change console input mode.

```bash
# Long-form Brainfork aliases
bfrun --fork fork_example.bf
bfrun --brainfork fork_example.bf

# Debug mode
bfrun --debug samples/programs/tests/cell_size_8bit.bf

# Programs that expect CRLF input or byte-at-a-time interactive input
bfrun -W samples/programs/io/echo2.bf
bfrun --immediate-stdin samples/programs/io/echo2.bf

# Interactive ANSI demo; requires random opcode and immediate stdin polling
bfrun -@ -I samples/programs/demos/tetris.bf
```

#### Hashbang options

BF files may carry `bfrun` options in the first line. If the file starts with
`#!`, `bfrun` treats that line as a hashbang, removes it before compiling the BF
program, finds the `bfrun` command in that line, and parses supported options
after it.

```brainfuck
#!/usr/bin/env bfrun -Y
```

This enables Brainfork for the file, the same as running:

```bash
bfrun -Y program.bf
```

```brainfuck
#!/usr/bin/bfrun -Y -@ --bits=16
```

This enables Brainfork, the random `@` opcode, and 16-bit cells.

For systems where `/usr/bin/env` needs split-string hashbang arguments, `-S` is
also fine because `bfrun` ignores interpreter tokens before the `bfrun` command:

```brainfuck
#!/usr/bin/env -S bfrun -Y -@ --bits=16
```

Supported hashbang options are the same runtime options accepted by the CLI:
`--bits=8|16|0`, `-Y` / `--fork` / `--brainfork`, `-d` / `--debug`, `-@` /
`--random`, `-W` / `--crlf-input`, and `-I` / `--immediate-stdin`. Unknown
hashbang options abort the run with an error.

Command-line options are parsed first; hashbang options are then applied from
the source file. Boolean options can only enable features, and `--bits=...` in
the hashbang overrides the earlier cell-width selection.

Because the hashbang line is stripped before compilation, its leading `#` never
triggers the debug opcode, even when `--debug` is enabled.

To run a BF file directly, make it executable and point the hashbang at `bfrun`:

```bash
chmod +x program.bf
./program.bf
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
| `16` | 0 – 65535 | wraps modulo 65536 | programs designed for 16-bit cells (e.g. `pi16.bf`) |
| `0` | 0 – PHP_INT_MAX | wraps modulo PHP_INT_MAX+1 | programs needing very large cell values |

```php
// 8-bit (standard): cell wraps 0→255 and 255→0
$c8  = new Compiler(8);
eval($c8->compile(str_repeat('-', 157) . '.'));   // prints chr(99)

// 16-bit: programs that rely on 16-bit arithmetic overflow
$c16 = new Compiler(16);
eval($c16->compile(file_get_contents('samples/programs/math/pi16.bf')));

// Wrap at PHP_INT_MAX: very large cell values, still unsigned
$c0  = new Compiler(0);
```

Programs like `beer.bf`, `bockbeer.bf`, `99_bottles.bf` and `ryan_beer.bf` initialise
their counter via 8-bit overflow (`0 − 157 = 99` in 8-bit BF) and require
`Compiler(8)` (the default) to run correctly.

## Extension opcodes

Beyond standard BF (`+ - < > [ ] . ,`), the compiler recognises:

| Opcode | Meaning |
|--------|---------|
| `#` | Debug: print current cell index and value (`$i: $d[$i]`) |
| `Y` | Optional ([Brainfork](https://esolangs.org/wiki/Brainfork)): fork via `pcntl_fork()` — parent zeroes the current cell; child moves the pointer right and sets that cell to `1`. |
| `@` | Optional random byte/word/integer: assign `random_int(0, N)` to the current cell, where `N` follows the configured cell width. |

`#` is always accepted. `Y` is accepted only when Brainfork is enabled (`new Compiler(..., brainfork: true)` or CLI `-Y` / `--fork` / `--brainfork`); `@` is accepted only when random mode is enabled (`new Compiler(..., randomOpcode: true)` or CLI `-@` / `--random`). Disabled extension opcodes are removed like any non-BF character (same idea as plain BF ignoring unknown symbols).

Requires the PHP **pcntl** extension when compiling or running programs that use `Y`.

## Optimisations

See [`AOT.md`](AOT.md) for a detailed explanation of the AOT pipeline,
bytecode-like IR, and pattern-based optimisations.

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
  programs/       — curated BF programs grouped by category (hello, math, demos, etc.)
  quines/         — dedicated quine collection
  collections/    — archival external collections (with their own readmes)
  README.md       — detailed guide to all samples, usage, cell sizes, and future plans
bfrun             — CLI entry point
```

## License

This project (compiler source, tests, tooling, and documentation outside third-party trees) is licensed under the [MIT License](LICENSE).

The **`samples/`** directory (see [`samples/README.md`](samples/README.md) for details) contains Brainfuck programs and collections gathered from various authors and websites for testing and demonstration. Those files are **not** necessarily covered by this project’s MIT license: rights remain with their respective authors, and license terms may differ. Where a subfolder includes its own `readme`, attribution, or license notes, those apply to the files in that tree; treat `samples/` as bundled third-party material unless stated otherwise.
