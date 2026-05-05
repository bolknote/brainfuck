# Brainfuck Test Matrix & Notable Programs

This document catalogs the programs in `samples/` and which aspects of the **PHP Brainfuck Compiler** they test.

## Compiler Features Tested

### Core Optimizations

| Feature | Programs | Description |
|---------|----------|-----------|
| **Run-length encoding** (`+++++` → `+=5`) | `programs/hello/*.bf`, most programs | Collapses consecutive identical instructions |
| **Opposing run cancellation** (`+++---` → nothing) | All math programs | Net effect of interleaved `+`/`-` or `>`/`<` |
| **Dead loop elimination** | `testLeadingLoopEliminated()` in tests | Leading `[` loops removed (tape starts at 0) |
| **Multiply-move optimization** (`[->+++<]`) | `MUL_PATTERN` in `CompilerTest.php`, many math programs | Replaced with direct arithmetic, no `while` loop |
| **Relative addressing** (`>+++<` → `$d[$i+1] += 3`) | Most programs with pointer movement | Avoids unnecessary pointer updates |
| **Loop body analysis** (Class A/B/C patterns) | Many tests in `CompilerTest.php` | One-shot loops, multiply patterns, nested loops |

### Cell Size Handling

| Cell Bits | Programs | Expected Behavior |
|-----------|----------|-------------------|
| **8 (default)** | `programs/hello/hello_world.bf`, `programs/demos/99_bottles.bf`, `programs/demos/beer.bf`, `programs/demos/bockbeer.bf`, `programs/demos/ryan_beer.bf` | Wraps at 256. Uses idiom `0 - 157 = 99` for counters |
| **16** | `programs/math/pi16.bf`, `programs/visual/mandelbrot_*` (some), tests with `executeWith(16, ...)` | Wraps at 65536. Used for larger arithmetic |
| **0 (unlimited)** | Tests only (`testNoCellBitsWrapsAtPhpIntMax()`) | Uses `PHP_INT_MAX`. For very large values |

### Special Opcodes

- **`#` (debug)**: Prints `index: value`. Tested in `testDebugOpcodeOutputsPointerAndCellValue()`, `testOutputIsNotBufferedPastDebugOpcode()`, `testMultiplyTempIsZeroed()`
- **`Y` (Brainfork)**: Only enabled with `new Compiler(brainfork: true)` or `-Y` flag. Tests verify both ignored and enabled behavior.

### Other Tested Behaviors

- Input handling (`testPrecompiledInputPassthrough`, `testIncrementInput`, `testReadSecondChar`)
- Loop edge cases (empty loops, nested loops, seek left/right)
- Self-modifying code (`programs/quines/self_mod_quine.bf`, `programs/experiments/`)
- Quines (stress output buffer, memory, parsing)

## Curated Notable Programs

### `programs/`

- **Hello World variants**: `hello_world.bf`, `hello_bf2.bf`, `hello_short.bf` (tests basic output)
- **Math**: `fibonacci.bf`, `prime.bf`, `pi16.bf`, `e.bf`, `golden_ratio.bf`, `power.bf`
- **Demos**: 
  - `99_bottles.bf`, `beer.bf` (8-bit wraparound)
  - `hanoi.bf`, `mandelbrot.bf`, `game_of_life.bf`, `triangle.bf`
- **Text**: `rot13.bf`, `sort.bf`, `htmlconv.bf`, `decss.bf`
- **Quines** (`programs/quines/`): `quine410.bf`, `quine414.bf`, `quine505.bf`, `self_portrait.bf`, `ryan_quine.bf`, `dquine.bf` + Bertram collection
- **Interpreters**: Multiple BF-in-BF interpreters (`bfi.bf`, etc.)
- **Visual**: Mandelbrot variants, ASCII art

### From Collections (Highlights)

- **fabianishere/**: Extremely high quality. Optimized mandelbrots (`mandelbrot_opt.bf`, `mandelbrot_tiny.bf`), math (`pi_digits.bf`, `prime_double.bf`), benchmarks, quines, sorts.
- **cristofani/**: Famous for clean, well-commented code and the shortest quines.
- **urban_mueller/**: Historical — original programs by the creator of Brainfuck.
- **esoteric_archive/**: Golf contest winners, libraries (`lib/math.bf`, `lib/array.bf`), many small utilities.

## Running the Test Suite

```bash
composer test
# or
vendor/bin/phpunit --configuration=config/phpunit.xml
```

The `sampleProvider()` in `CompilerTest.php` runs a selection of real programs from `programs/hello/` and `collections/cristofani/`.

## Future Work

- Add automated output verification for more programs (golden files).
- Benchmark suite comparing different cell sizes and optimization levels.
- Script to run all programs in `programs/` with expected output checks.
- More curation from `fabianishere/` and `esoteric_archive/` into the main `programs/` tree with clear provenance comments.

See `samples/README.md` for full directory explanation and `src/Compiler.php` for implementation details.
