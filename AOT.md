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

The historical JavaScript version described similar ideas as JIT-like pattern
substitutions. The PHP version is better described as an AOT compiler with an IR
and peephole/pattern-based optimisation passes.

## Pipeline

Compilation is split into several stages:

1. **Source filtering**  
   All characters that are not BF commands or enabled extensions (`#`, `Y`) are
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
   | `[-]`, `[+]` | `c` | clear current cell |
   | `[>]` | `r` | scan right to zero |
   | `[<]` | `l` | scan left to zero |

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
because of wrap-around. The compiler therefore emits a guard:

- if the value is not suitable for the fast path, the original `while` runs;
- if it is suitable, the fast calculation is used.

For power-of-two divisors the compiler uses a bit shift:

```php
(($d[$i]??0)>>1)
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

Loops that contain `[-]` need separate analysis. The compiler recognises two
useful groups.

**One-shot loop**: if the loop body clears its controller cell, the loop can run
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

**Constant-set loop**: if non-controller cells are cleared before receiving
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

## Terminology

A concise description:

> Brainfuck-to-PHP AOT transpiler with a bytecode-like IR and
> peephole/pattern-based loop optimizations.

Useful terms for this project:

- **AOT compiler** - compiles before execution;
- **source-to-source compiler / transpiler** - emits PHP source code;
- **bytecode-like IR** - compact internal opcodes used for optimisation;
- **peephole optimizer** - local rewrites of small instruction windows;
- **pattern-based optimizer** - recognition of larger BF idioms;
- **strength reduction** - replacement of loops with arithmetic expressions;
- **dead-code elimination** - removal of loops known not to execute;
- **constant folding / constant propagation** - direct assignments after clears
  and constant-set patterns.

The term **JIT** should not be used as the main description. Historically, the
JavaScript version can be described as using JIT-like code generation, but the
current PHP version is architecturally closer to an AOT compiler.
