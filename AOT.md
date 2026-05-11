# AOT Compilation and Brainfuck Optimisations

This project compiles Brainfuck to PHP code before the program runs. More
precisely, it is an **AOT source-to-source compiler / transpiler**:

- **AOT** (*ahead-of-time*) - the BF program is translated before the generated
  PHP code is executed.
- **Source-to-source** - the compiler output is source code in another language:
  PHP.
- **Not JIT** - optimisation decisions are not made during execution from a
  runtime profile. The compiler does not observe hot paths or recompile code on
  the fly.

Internally, the compiler uses a compact intermediate representation. It is fair
to call it a **bytecode-like IR** or **pseudo-bytecode**, but it is not VM
bytecode in the strict sense: it is not interpreted by a bytecode engine. It is
a convenient form for rewriting Brainfuck patterns before generating PHP.

*Note: The generated PHP code examples below are shown in the dense format produced by the transpiler to reduce output file size.*

## Pipeline

Compilation is split into several stages:

1. **Source filtering**  
   All characters that are not BF commands or enabled extensions (`#`, `Y`, `@`) are
   removed.

2. **IR translation**  
   BF commands are mapped to compact internal opcodes:

   | BF | IR | Meaning |
   |----|----|---------|
   | `+` | `P` | increment cell |
   | `-` | `M` | decrement cell |
   | `>` | `p` | move pointer right |
   | `<` | `m` | move pointer left |
   | `[` | `L` | loop begin |
   | `]` | `R` | loop end |
   | `.` | `E` | output |
   | `,` | `,` | input |
   | `[-]`, `[+]` | `c` | clear current cell |
   | `[>]` | `r` | scan right to zero |
   | `[<]` | `l` | scan left to zero |

   A few symbols are internal-only:

   | IR | Meaning |
   |----|---------|
   | `W` | raw `while` begin; used for fallback loops that must not be optimised again |
   | `00`-`99` prefix | run-length count attached to `P`, `M`, `p`, or `m` |

   Extension opcodes are preserved only when enabled: `#` for debug output,
   `Y` for Brainfork, and `@` for random cell values.

   `W` is deliberately separate from `L`. Both eventually generate
   `while($d[$i]??0){`, but they have different roles inside the optimiser:
   `L...R` means "this loop is still eligible for loop optimisation", while
   `W...R` means "emit this loop literally". This is needed when an optimisation
   creates a guarded fallback path. Re-running loop optimisation on that fallback
   could turn it back into the fast path and break the guard's purpose.

3. **Normalisation and local simplification**  
   Dead regions are removed, opposing operations are cancelled, and repeated
   commands are encoded with a count.

4. **Loop and pattern optimisation**  
   The compiler recognises BF idioms: clearing, copying, multiplication,
   scanning, constant-set loops, and selected conditional cases.

5. **PHP generation**  
   The remaining IR is translated to PHP: cell arithmetic, pointer movement,
   `while`, `for`, `if`, input/output, and extension opcodes.

## Main Techniques

### Run-Length Encoding

Repeated `+`, `-`, `<`, and `>` commands are collapsed into one operation with a
constant count:

```brainfuck
+++++
```

becomes code shaped like:

```php
$d[$i]=(($d[$i]??0)+5)&255;
```

The same applies to pointer movement:

```brainfuck
>>>>>
```

becomes:

```php
$i+=5;
```

This reduces generated PHP size and runtime work.

Run lengths are encoded with a two-digit count prefix (`00`-`99`) in the IR.
Longer runs are therefore emitted as multiple counted chunks rather than one
unbounded integer token.

### Opposing-Run Cancellation

Opposing operations are reduced to their net effect:

```brainfuck
++++---
```

is equivalent to:

```brainfuck
+
```

Pointer movement is handled the same way:

```brainfuck
>>><
```

is equivalent to:

```brainfuck
>>
```

This happens before PHP generation, so the removed operations never reach the
generated code.

### Dead-Loop Elimination

Some loops can be removed because the compiler knows that the current cell is
zero.

Examples:

- a loop at the start of a program: the initial cell is always `0`;
- a loop after `[-]` or `[+]`: the cell has been cleared;
- a loop after a recognised balanced copy/multiply loop that zeroes its
  controller cell;
- a loop after a scan loop (`[>]`, `[<]`), which terminates on a zero cell.

For example:

```brainfuck
++[-][->+<]
```

The second loop is dead: after `[-]`, the current cell is already `0`.

### Clear-Loop Folding

Classic BF clearing idioms:

```brainfuck
[-]
[+]
```

are replaced with a direct assignment:

```php
$d[$i]=0;
```

This is an important base optimisation because several later rules rely on the
fact that the current cell is known to be zero after this point.

### Constant-Load Folding

If a clear operation is immediately followed by a constant add/subtract run, the
sequence becomes a direct value load:

```brainfuck
[-]+++++
```

becomes:

```php
$d[$i]=5;
```

In 8-bit and 16-bit modes the value is masked to the configured cell width.

### Relative Addressing

Patterns that move to a nearby cell, modify it, and move back are replaced with
direct relative indexing:

```brainfuck
>+++<
```

becomes:

```php
$d[$i+1]=(($d[$i+1]??0)+3)&255;
```

The pointer does not move back and forth; the operation is applied directly to
the target cell.

Larger offsets work too:

```brainfuck
>>>[-]<<<
```

becomes a direct clear:

```php
$d[$i+3]=0;
```

### Scan-Loop Optimisation

Loops that scan for the next zero cell:

```brainfuck
[>]
[<]
[>>]
[<<<]
```

compile to a compact pointer-stepping loop:

```php
for(;$d[$i]??0;++$i);
```

or, for wider steps:

```php
while($d[$i]??0){$i+=2;}
```

This preserves BF behaviour while avoiding a fully general loop body made from
individual BF operations.

### Linear Loop Optimisation

Many BF loops linearly transfer the controller cell value to other cells:

```brainfuck
[->+<]
```

Instead of executing the loop `N` times, the compiler emits its total effect:

```php
$d[$i+1]=(($d[$i+1]??0)+($d[$i]??0))&255;
$d[$i]=0;
```

A more general example:

```brainfuck
[->++>+++<<]
```

means:

- `cell[1] += cell[0] * 2`;
- `cell[2] += cell[0] * 3`;
- `cell[0] = 0`.

These loops lose the `while` entirely, which is usually one of the biggest
wins.

### Divider-Loop Fast Path

Some loops decrement the controller cell by `D` rather than by `1`:

```brainfuck
[-->+<]
```

If the starting value is divisible by `D`, the loop can be replaced with a
division:

```php
$d[$i+1] += $d[$i] / 2;
$d[$i] = 0;
```

For 8-bit BF, if the value is not divisible by `D`, behaviour can be non-trivial
because of wrap-around (e.g., subtracting 3 repeatedly from a cell might skip 0 due to modulo 256 arithmetic, causing an infinite loop). The compiler therefore emits a guard:

- if the value is not suitable for the fast path, the original `while` runs;
- if it is suitable, the fast calculation is used.

The guarded fallback is encoded with `W...R` rather than `L...R`. This tells the
remaining compiler passes to generate a raw `while` for the fallback body. If
the fallback used `L`, the same loop optimiser could see it again and replace it
with the fast form that the guard was supposed to avoid.

For power-of-two divisors the compiler uses a bit shift:

```php
$d[$i+1] += (($d[$i]??0)>>1);
$d[$i] = 0;
```

instead of division.

### Nested Multiplication Optimisation

The compiler recognises the canonical BF multiplication pattern with a temporary
cell:

```brainfuck
[->[->+>+<<]>>[-<<+>>]<<<]
```

The pattern means:

- the outer cell `A` controls the iteration count;
- value `B` is copied through a temporary cell;
- the destination receives `A * B`;
- `B` is preserved;
- the temporary cell is cleared;
- `A` is zeroed.

Instead of nested loops, the compiler emits a multiplication expression:

```php
$d[$i+2]=(($d[$i+2]??0)+($d[$i]??0)*($d[$i+1]??0))&255;
$d[$i]=0;
```

This matters for programs that build arithmetic out of standard BF macros.

### Loop-With-Clear Optimisation

Loops that contain `[-]` need separate analysis. The compiler recognises several
useful groups.

#### One-Shot Loop

If the loop body clears its controller cell, the loop can run
at most once.

```brainfuck
[[-]>+<]
```

is equivalent to:

```php
if($d[$i]??0){
    $d[$i+1]=(($d[$i+1]??0)+1)&255;
    $d[$i]=0;
}
```

#### Constant-Set Loop

If non-controller cells are cleared before receiving
constant values, the result does not depend on the number of iterations.

```brainfuck
[<[-]+>-]
```

becomes:

```php
if($d[$i]??0){
    $d[$i-1]=1;
    $d[$i]=0;
}
```

This replaces a potentially long loop with a single `if`.

#### Linear Loop with Clear Side Effects

If the controller is decremented while
other cells are cleared or assigned constants, the linear transfer and the
conditional clear can be emitted directly.

```brainfuck
[>+>[-]<<-]
```

becomes code shaped like:

```php
if($d[$i]??0){
    $d[$i+2]=0;
}
$d[$i+1]=(($d[$i+1]??0)+($d[$i]??0))&255;
$d[$i]=0;
```

This captures common clear/merge idioms without matching generated PHP; the
loop body is analysed in the BF/IR form before emission.

#### Pointer-Changing One-Shot Loops

BF checks `]` at the pointer position left
by the loop body. If that final cell is definitely cleared, the loop can run at
most once even if the pointer does not return to the original controller cell.

```brainfuck
[>[-]]
```

becomes code shaped like:

```php
if($d[$i]??0){
    $d[++$i]=0;
}
```

The optimiser only applies this when the final pointer cell is known to be zero;
unsafe loops such as `[>[-]+]`, `[>[-]<]`, or `[>+]` remain ordinary `while`
loops.

#### Nested One-Shot Conditionals

Macro-style `if(a) ... endif(a)` patterns can
contain nested copy/move loops. When the top-level body clears the controller,
the pointer movement is balanced, and all nested loops reduce to straight-line
or conditional code, the outer loop becomes a single `if`.

```brainfuck
[>[->+<]<[-]]
```

The nested transfer is optimised first, then the surrounding one-shot loop is
emitted as a conditional.

### Sparse Tape

The tape is stored as a sparse PHP array:

```php
$d=[];
```

Cells default to zero through `??0`. This is cheaper than pre-filling a large
fixed-size array, especially for programs that only touch a small tape region.

The initial pointer is placed in the middle of the allowed range so programs can
move both left and right.

### Cell-Width Specialisation

The compiler specialises arithmetic for the configured cell mode:

- 8-bit: `&255`;
- 16-bit: `&65535`;
- unbounded: no mask, raw PHP integer values.

This preserves the requested BF semantics without adding masking work in
unbounded mode.

One small code-generation detail follows from this: bounded modes keep cell
values non-negative through masking, but unbounded mode may contain negative
integers. For optimised linear loops with negative factors, the generated PHP
uses the absolute value of the source term and applies the sign separately:

```php
$d[$i+1]=($d[$i+1]??0)-abs($d[$i]??0)*3;
```

This is not a separate BF rewrite; it is the unbounded-mode emission form for
the same linear-loop optimisation described above.

### PHP Emission Details

Some generated PHP forms are not separate BF optimisations, but they matter when
reading compiler output.

**Output is always byte-sized.**  The `.` opcode emits `chr()` of the current
cell masked to one byte, even in 16-bit or unbounded cell modes:

```php
echo chr(($d[$i]??0)&255);
```

Cell arithmetic may use `&65535` or no mask, but output remains byte-oriented.

**Input refill code depends on input mode.**  The `,` opcode consumes a queue of
input bytes. When the queue is empty, generated PHP refills it from `STDIN`.
Line-buffered mode uses `fgets()`, immediate mode uses `fgetc()`, and CRLF mode
adds the same lone-LF normalisation used for prefilled input. These blocks are
runtime I/O handling, not peephole optimisation.

**Pointer side effects are split from cell writes.**  Some peephole patterns
combine pointer movement and cell arithmetic, such as `++>>` or `<<+`. When the
cell address would otherwise contain a side-effecting expression (`$i++`,
`--$i`, `$i+=N`), the compiler emits the pointer update as a separate statement
so the cell reference is evaluated exactly once.

**Extension opcodes are direct emissions.**  When enabled, `#`, `Y`, and `@`
compile to debug output, `pcntl_fork()`, and `random_int()` respectively:

```php
echo "$i: ".($d[$i]??0)."\n";
$pid=pcntl_fork();if($pid)$d[$i]=0;else $d[++$i]=1;
$d[$i]=random_int(0,255);
```

The random upper bound follows the selected cell width: `255`, `65535`, or
`PHP_INT_MAX`.

## Terminology

Useful terms for this project:

- **bytecode-like IR** - compact internal opcodes used for optimisation;
- **peephole optimizer** - local rewrites of small instruction windows;
- **pattern-based optimizer** - recognition of larger BF idioms;
- **strength reduction** - replacement of loops with arithmetic expressions;
- **dead-code elimination** - removal of loops known not to execute;
- **constant folding / constant propagation** - direct assignments after clears
  and constant-set patterns.
