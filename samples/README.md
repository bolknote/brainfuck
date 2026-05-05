# Samples Directory

This directory contains a comprehensive collection of Brainfuck (BF) programs. They serve multiple purposes:

- **Testing** the PHP compiler's correctness, optimizations, cell size handling, and edge cases.
- **Demonstration** of what BF can do.
- **Archival** of historical collections from the BF community.

## Directory Structure

### `programs/` — Curated & Categorized Examples

Clean, organized selection of programs grouped by purpose. These are the primary ones used for testing and demos. Most are well-known classics or interesting implementations.

**Subdirectories:**

- `hello/` — Hello World and variants (including short/optimized versions)
- `math/` — Mathematical computations (fibonacci, primes, pi digits, e, golden ratio, powers, etc.)
- `demos/` — Classic demos and visual programs:
  - 99 bottles of beer variants
  - Hanoi tower
  - Mandelbrot set
  - Game of Life
  - Triangles and ASCII art
- `text/` — Text processing and transformation (rot13, sorting, HTML conversion, decss)
- `interpreters/` — Brainfuck interpreters and compilers written *in* Brainfuck
- `io/` — Input/output helpers and utilities
- `experiments/` — Experimental, unusual, or self-modifying code
- `tests/` — Programs targeting specific compiler behaviors
- `benchmarks/` — Performance and optimization test cases (curated from fabianishere)
- `algorithms/` — Sorting, search and other algorithmic implementations
- `visual/` — Visual/graphic programs (mandelbrot, ascii art, game of life, etc.)

See individual files for comments on expected output, cell size requirements, and source attribution.

### `programs/quines/` — Quine Collection

Dedicated directory for **self-reproducing programs** (programs that output their own source code). Contains many size-optimized quines:

- `quine410.bf`, `quine414.bf`, `quine505.bf` and variants
- `self_portrait.bf`, `ryan_quine.bf`, `dquine.bf`, etc.
- `bertram/` subdirectory (historical bootstrap files from Bertram's minimal quine research)

Additional quines and variants exist in `collections/fabianishere/quine/` and `collections/esoteric_archive/`.

### `collections/` — Historical & External Archives

**Preserved copies** of large external collections. These maintain their original organization, filenames, and accompanying documentation (readmes, libs, contest results) to preserve context and attribution. 

**Main collections:**

- `urban_mueller/` — Programs by Urban Müller, the creator of Brainfuck (including the original `hello.bf`, `prime.bf`, etc.)
- `fabianishere/` — Extensive collection (~40 programs) from https://github.com/fabianishere/brainfuck — includes optimized mandelbrots, math libraries, benchmarks, quines, sorts, and more. Subdirs for `mandelbrot/`, `math/`, `quine/`, `bench/`, `asciiart/`, etc.
- `esoteric_archive/` — Material from https://esoteric.sange.fi/brainfuck/ :
  - `lib/` — Utility libraries (array, math, input, etc.)
  - `src_bf/` — Source programs
  - `bf_golf_results/` — Results from Brainfuck golfing contests (text-to-BF, von Neumann, sorting contests) with docs
- `cristofani/` — Daniel Cristofani's famous programs and interpreter
- `frans_faase/`, `brian_raiter/`, `mazonka_cristofani_self_interpreter/`, `bfide_examples/` — Other notable contributors and examples

Files were deduplicated against the rest of `samples/` using exact SHA-256 hashes where possible. See each subdirectory's `readme.md` for full source details and licenses.

**Note:** These are treated as third-party bundled material. Do not modify internal structure lightly.

## Usage Examples

```bash
# Basic usage (8-bit cells, default)
php run.php samples/programs/hello/hello_world.bf

# 16-bit cells (for programs like pi16.bf that rely on 16-bit overflow)
php run.php --bits=16 samples/programs/math/pi16.bf

# With pre-filled input
php run.php samples/programs/io/echo2.bf   # (provide input via stdin or modify)

# Brainfork (if any Y opcodes)
php run.php -Y some_fork_program.bf
```

See `CompilerTest.php` for automated tests that exercise optimizations (loop elimination, RLE, multiply-move patterns, relative addressing, etc.).

## Input Requirements

Some programs were written for specific input formats and will hang or exhaust memory if given the wrong terminator:

| Program | Expected terminator | How to run |
|---|---|---|
| `hello/hello_you.bf` | `\r` (CR, ASCII 13) | `printf "Alice\r" \| php run.php ...` |
| `math/rpn.bf` | `\r` (CR, ASCII 13) | `printf "3 4 +\r" \| php run.php ...` |
| `text/sort.bf` | byte `0xFF` (255) | `printf "hello\xff" \| php run.php ...` |
| `text/bertram_sort.bf` | byte `0xFF` (255) | `printf "hello\xff" \| php run.php ...` |

**Why they hang:** these programs use input to detect end-of-line by checking for a specific sentinel value. When given a Unix `\n` or EOF=0, the sentinel is never found — the loop runs forever with `++$i` on every iteration, growing the sparse tape array until OOM.

`quines/self_mod_quine.bf` is inherently memory-intensive due to its self-modifying nature.

## Cell Size Requirements

See main `README.md` for details on `$cellBits` parameter. Notable examples:

- **8-bit (default)**: `beer.bf`, `99_bottles.bf`, `bockbeer.bf`, `ryan_beer.bf` — rely on wraparound arithmetic (e.g. `0 - 157 = 99`).
- **16-bit**: `pi16.bf`, some mandelbrot variants.
- **Unlimited**: Programs needing very large integers.

Many programs in `collections/` document their requirements in accompanying files.

## Notable Programs & Features Tested

**From `programs/` (curated):**

- **Hello World family**: `hello_world.bf`, `hello_short.bf`, `hello_bf2.bf`
- **Math & Algorithms**: `pi16.bf`, `fibonacci.bf`, `prime.bf`, `e.bf`, `rpn.bf`, `power.bf`
- **Visual/Demos**: `mandelbrot.bf`, `mandelbrot_opt.bf`, `mandelbrot_tiny.bf`, `game_of_life.bf`, `hanoi.bf`, `hanoi_opt.bf`, `triangle.bf`
- **Quines**: Full collection in `programs/quines/` (410–505 byte classics, `self_portrait.bf`, Bertram series)
- **Benchmarks & Cell tests**: `bench_1.bf`, `easy_opt.bf`, `cell_size_*.bf`
- **Text/IO**: `rot13.bf`, `htmlconv.bf`, `decss.bf`, multiple interpreters

**Key compiler features exercised** (see `samples/docs/test-matrix.md` for full matrix):
- All major optimizations (RLE, multiply-move, relative addressing, dead code elimination)
- 8-bit wraparound (beer/99_bottles), 16-bit (`pi16.bf`), unlimited cells
- Debug opcode `#`, Brainfork `Y`, complex nested loops, self-modifying code

See `samples/docs/test-matrix.md` for detailed mapping of programs → tested compiler passes.

## License Note

The main project is MIT licensed. All content in `samples/` consists of third-party Brainfuck programs. Individual subdirectories contain their own attribution, readmes, or license notes where available. Respect original authors' rights.

## Status & Future Improvements

**Done:**
- Quines consolidated into `programs/quines/`
- Comprehensive `docs/test-matrix.md` created
- Curated high-quality programs from `fabianishere/` (optimized mandelbrots, benchmarks, cell size tests, `hanoi_opt.bf`, `e.bf`, etc.) with attribution headers

**Remaining:**
1. **Curated index** — Create `programs/index.md` or JSON manifest with expected outputs/cell sizes.
2. **More curation** — Bring in top programs from `esoteric_archive/` (golf winners, libraries).
3. **Automation** — Script to run all `programs/*.bf` with golden output verification.
4. **Documentation** — Add comparison table of different BF interpreters/compilers.
5. **Cleanup** — Decide on `samples/quines/bertram/` (historical) and update any remaining references.

This structure now provides excellent **discoverability** while preserving original collections.

This structure balances **usability** (easy-to-find categorized programs) with **preservation** (historical collections untouched).

For questions about specific programs, check their containing `readme.md` files or original sources.
