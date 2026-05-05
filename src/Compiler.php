<?php

declare(strict_types=1);

namespace BolkNote\Brainfuck;

class Compiler
{
    public const int CELL_BITS_UNBOUNDED = 0;
    public const int CELL_BITS_8 = 8;
    public const int CELL_BITS_16 = 16;
    public const int DEFAULT_CELL_BITS = self::CELL_BITS_8;

    private const int TAPE_EXTENT = 65535;
    private const int TAPE_SIZE = self::TAPE_EXTENT * 2;
    private const int MAX_REPEAT = 99;

    /** 8-bit cell mask; also limits `chr()` argument to a byte (see compileCode `E`). */
    private const int MASK_BYTE = 0xFF;
    private const int MASK_WORD = 0xFFFF;
    private const int MASK_INT  = PHP_INT_MAX;

    /** Bitmask applied to every cell write: MASK_BYTE / MASK_WORD / MASK_INT. */
    private readonly int $cellMask;

    /**
     * @param int  $cellBits  Cell width in bits.
     *                        CELL_BITS_8  — standard BF (default): cells wrap at 256.
     *                        CELL_BITS_16 — extended BF: cells wrap at 65536.
     *                        CELL_BITS_UNBOUNDED — cells wrap at PHP_INT_MAX.
     * @param bool $brainfork If true, opcode `Y` is recognised ([Brainfork](https://esolangs.org/wiki/Brainfork):
     *                        fork via `pcntl_fork()`). If false, `Y` is stripped like any non-BF character
     *                        (pure Brainfuck).
     */
    public function __construct(
        int $cellBits = self::DEFAULT_CELL_BITS,
        private readonly bool $brainfork = false,
    ) {
        $this->cellMask = match ($cellBits) {
            self::CELL_BITS_8         => self::MASK_BYTE,
            self::CELL_BITS_16        => self::MASK_WORD,
            self::CELL_BITS_UNBOUNDED => self::MASK_INT,
            default => throw new \InvalidArgumentException('cellBits must be 0, 8, or 16'),
        };
    }

    /**
     * Compile a BF program to a self-contained PHP string.
     *
     * @param string $str   BF source code
     * @param string $input Optional pre-filled input (converted to char codes)
     * @return string       Executable PHP code
     */
    public function compile(string $str, string $input = ''): string
    {
        return $this->addHeader($this->toPHP($str), $input);
    }

    /**
     * Prepend the tape/pointer initialisation and optional pre-filled input
     * to already-compiled BF body code.
     */
    public function addHeader(string $str, string $input = ''): string
    {
        $tapeStart = intdiv(self::TAPE_SIZE, 2);
        $str = '$d=[];$i=' . $tapeStart . ';' . $str;

        $codes = [];
        if ($input !== '') {
            $unpacked = unpack('c*', $input . "\0");
            if (is_array($unpacked)) {
                $codes = array_map(
                    static fn (mixed $b): string => match (true) {
                        is_int($b) => (string) $b,
                        is_string($b) => $b,
                        default => '0',
                    },
                    array_values($unpacked),
                );
            }
        }

        return '$in=[' . implode(',', $codes) . '];' . $str;
    }

    /**
     * Translate BF source to PHP body code (without the tape header).
     */
    public function toPHP(string $str): string
    {
        return $this->compileCode($this->prepare($str));
    }

    /**
     * Strip non-BF characters, encode opcodes into an internal alphabet,
     * eliminate dead opening loops, and group repeated moves/increments.
     */
    protected function prepare(string $str): string
    {
        $forkChars = $this->brainfork ? 'Y' : '';
        $str       = preg_replace("/[^\\-+\\[\\]><,.#{$forkChars}]+/", '', $str) ?? '';

        $trans = [
            '[<]' => 'l',
            '[>]' => 'r',
            '[-]' => 'c',
            '[+]' => 'c',
            '+' => 'P',
            '-' => 'M',
            '<' => 'm',
            '>' => 'p',
            '[' => 'L',
            ']' => 'R',
            '.' => 'E',
        ];

        $str = strtr($str, $trans);

        // Drop a loop at the very start: cell is always 0 there, so the loop never executes.
        $str = preg_replace('/^[lrcmp]* L ( ( (?>[^LR]+) | (?R) )* ) R/x', '', $str) ?? '';

        // Cancel out opposing runs: +++-- → +, >>>< → >>
        foreach (['MP', 'mp'] as $set) {
            $str = preg_replace_callback("/[$set]{2,}/S", static function ($m) {
                $leftOpcode = $m[0][0];
                $rightOpcode = match ($leftOpcode) {
                    'M' => 'P',
                    'P' => 'M',
                    'm' => 'p',
                    default => 'm',
                };
                $diff = substr_count($m[0], $leftOpcode) - substr_count($m[0], $rightOpcode);

                if ($diff > 0) {
                    return str_repeat($leftOpcode, $diff);
                }

                if ($diff < 0) {
                    return str_repeat($rightOpcode, -$diff);
                }

                return '';
            }, $str) ?? '';
        }

        // Encode run lengths: PPP → 03P  (max MAX_REPEAT per group)
        $str = preg_replace_callback(
            '/([PMpm])(\\1{1,' . (self::MAX_REPEAT - 1) . '})/',
            static fn ($m) => sprintf('%02d%s', strlen($m[2]) + 1, $m[1]),
            $str,
        ) ?? '';

        // Dead loops after cell-zeroing single-char ops (c/l/r always leave $d[$i] = 0).
        $str = preg_replace('/([clr])(L((?>[^LR]+)|(?R))*R)+/x', '$1', $str) ?? $str;

        // Dead loops after balanced multiply/copy loops (M or c in body + balanced moves
        // → $d[$i] = 0 at exit). Insert temporary Z marker, then strip following loops.
        $str = preg_replace_callback('/L([MPmpc\d]+)R/', function (array $m): string {
            $body = $m[1];
            if (!str_contains($body, 'M') && !str_contains($body, 'c')) {
                return $m[0];
            }
            $mCount = 0;
            $pCount = 0;
            preg_match_all('/(\d{2}|)([mp])/S', $body, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $count = (int) ($match[1] ?: 1);
                $match[2] === 'm' ? $mCount += $count : $pCount += $count;
            }
            return ($mCount === $pCount) ? $m[0] . 'Z' : $m[0];
        }, $str) ?? $str;
        $str = preg_replace('/Z(L((?>[^LR]+)|(?R))*R)+/x', 'Z', $str) ?? $str;
        $str = str_replace('Z', '', $str);

        // Second c/l/r pass: catch dead loops newly exposed by Z removal.
        $str = preg_replace('/([clr])(L((?>[^LR]+)|(?R))*R)+/x', '$1', $str) ?? $str;

        return $str;
    }

    /**
     * Build a relative-addressing cell operation: e.g. `$d[$i+3]+=5;`
     *
     * @param string     $dir   Direction opcode: 'm' (left) or 'p' (right)
     * @param string|int $shift Distance from current pointer
     * @param string     $col   Constant operand (empty = single step)
     * @param string     $op    Operation opcode: 'M' (sub), 'P' (add), 'c' (clear)
     */
    protected function dirOp(string $dir, string|int $shift, string $col, string $op): string
    {
        $sign = $dir === 'm' ? '-' : '+';
        $distance = $shift ? (int) $shift : 1;
        $ref = '$d[$i' . $sign . $distance . ']';

        if ($op === 'c') {
            return $ref . '=0;';
        }

        $operator = $op === 'M' ? '-' : '+';
        $amount = $col !== '' ? (int) $col : 1;

        return $this->wrapCell($ref, $operator, $amount);
    }

    /**
     * Build a simple pointer-or-cell operation, optionally at a computed index.
     *
     * When $idx carries a pointer side-effect (e.g. '$i++', '--$i', '$i+=3'),
     * the pointer update is emitted as a separate statement so the cell address
     * is only evaluated once — safe for both wrapped and non-wrapped modes.
     *
     * @param string|int  $repeat  Run length (0 = single step, N = N steps)
     * @param string      $op      Opcode
     * @param string|null $idx     Pointer expression (null = use current $i)
     */
    protected function op(string|int $repeat, string $op, ?string $idx = null): string
    {
        $repeat = (int) $repeat;

        if ($op === 'm') {
            return $repeat ? '$i-=' . $repeat . ';' : '--$i;';
        }
        if ($op === 'p') {
            return $repeat ? '$i+=' . $repeat . ';' : '++$i;';
        }

        $cell = $idx === null ? '$d[$i]' : '$d[' . $idx . ']';

        if ($op === 'c') {
            return $cell . '=0;';
        }

        if ($op !== 'M' && $op !== 'P') {
            return str_repeat($op, 1 + $repeat);
        }

        $amount = $repeat ?: 1;
        $operator = $op === 'M' ? '-' : '+';

        if ($idx === null) {
            return $this->wrapCell('$d[$i]', $operator, $amount);
        }

        // $idx contains a side-effecting pointer expression ($i++, --$i, $i+=N …).
        // Split it into a separate statement so the cell address is read exactly once.
        return $this->cellOpWithPointerSideEffect($operator, $amount, $idx);
    }

    /**
     * Emit a cell operation where the pointer address carries a side-effect.
     *
     * Post-operations ($i++, $i--): cell is accessed at the *current* $i,
     *   then the pointer changes.
     * Pre-operations (++$i, --$i, $i+=N, …): pointer changes first,
     *   then the cell at the *new* $i is accessed.
     */
    private function cellOpWithPointerSideEffect(string $operator, int $amount, string $idx): string
    {
        $cellOp = $this->wrapCell('$d[$i]', $operator, $amount);

        // Post-increment / post-decrement: cell first, then pointer.
        if ($idx === '$i++') {
            return $cellOp . '$i++;';
        }
        if ($idx === '$i--') {
            return $cellOp . '$i--;';
        }

        // Pre-increment / pre-decrement / compound assignment: pointer first, then cell.
        return $idx . ';' . $cellOp;
    }

    /**
     * Emit a cell-write expression, wrapping the result to $cellMask when set.
     *
     * With mask:   `$d[$i]=($d[$i]+3)&255;`
     * Without:     `$d[$i]+=3;`  or  `$d[$i]++;`
     */
    private function wrapCell(string $ref, string $op, int $amount): string
    {
        if ($this->cellMask === self::MASK_INT) {
            // Unbounded mode: no masking, use raw PHP integers.
            return $ref . '=(' . $ref . '??0)' . $op . $amount . ';';
        }

        return $ref . '=((' . $ref . '??0)' . $op . $amount . ')&' . $this->cellMask . ';';
    }

    /**
     * PCRE group value as string (match arrays are untyped for static analysis).
     *
     * @param array<int|string, mixed> $m
     */
    private static function pcreGroup(array $m, int $index): string
    {
        if (! array_key_exists($index, $m)) {
            return '';
        }

        $v = $m[$index];
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return '';
    }

    /**
     * Build a `$d[$i±offset]` reference string for a given relative offset.
     */
    private function cellRef(int $offset): string
    {
        return match (true) {
            $offset > 0  => '$d[$i+' . $offset . ']',
            $offset === 0 => '$d[$i]',
            default       => '$d[$i' . $offset . ']',
        };
    }

    /**
     * Parse a flat loop body and extract the controller step (divider) and the
     * per-iteration delta for every non-controller cell.
     *
     * Rules:
     * - The first `M` opcode encountered at pointer position 0 is the controller;
     *   subsequent `M` ops at position 0 add to the same divider.
     * - Any `P` opcode at position 0 means an increment-controller loop that
     *   relies on 8-bit wrap-around; those cannot be safely linearised, so null
     *   is returned.
     * - Any opcode other than M, P, m, p also causes a null return.
     *
     * @return array{divider: int, effects: array<int, int>}|null
     */
    private function collectLoopEffects(string $str): ?array
    {
        $pos     = 0;
        $divider = 0;
        /** @var array<int, int> $effects */
        $effects = [];

        $len = strlen($str);
        for ($k = 0; $k < $len; $k++) {
            if ((string) (int) $str[$k] === $str[$k]) {
                $num = (int) substr($str, $k++, 2);
                $op  = $str[++$k];
            } else {
                $num = 1;
                $op  = $str[$k];
            }

            switch ($op) {
                case 'm':
                    $pos -= $num;
                    break;

                case 'p':
                    $pos += $num;
                    break;

                case 'M':
                    if ($pos === 0) {
                        $divider += $num;
                    } else {
                        $effects[$pos] = ($effects[$pos] ?? 0) - $num;
                    }
                    break;

                case 'P':
                    if ($pos === 0) {
                        // Increment at controller position: wrap-around loop, cannot linearise.
                        return null;
                    }
                    $effects[$pos] = ($effects[$pos] ?? 0) + $num;
                    break;

                default:
                    return null; // c, l, r, or unexpected opcode
            }
        }

        return $divider > 0 ? ['divider' => $divider, 'effects' => $effects] : null;
    }

    /**
     * Generate straight-line PHP for a loop where every per-iteration delta
     * divides evenly by $divider.  All effects are expressed as `$d[$i] * K`
     * multiplications (integer K).
     *
     * @param array<int, int> $effects  offset => net delta per iteration
     */
    private function genStraightLine(array $effects, int $divider): string
    {
        ksort($effects);
        $out = '';

        foreach ($effects as $offset => $delta) {
            $factor = intdiv($delta, $divider);
            if ($factor === 0) {
                continue;
            }

            $ref    = $this->cellRef($offset);
            $absF   = abs($factor);
            $op     = $factor > 0 ? '+' : '-';
            $mult   = $absF === 1 ? '($d[$i]??0)' : '($d[$i]??0)*' . $absF;

            if ($this->cellMask !== self::MASK_INT) {
                $out .= $ref . '=((' . $ref . '??0)' . $op . $mult . ')&' . $this->cellMask . ';';
            } else {
                $absOp = $absF === 1 ? 'abs($d[$i]??0)' : 'abs($d[$i]??0)*' . $absF;
                $out .= $ref . '=(' . $ref . '??0)' . $op . $absOp . ';';
            }
        }

        return $out . '$d[$i]=0;';
    }

    /**
     * Generate the "fast path" PHP for the else-branch of a conditional
     * optimisation where some per-iteration deltas do not divide evenly by
     * $divider.  The guard guarantees that $d[$i] % $divider === 0 here, so
     * it is safe to use intdiv (or a bitshift for power-of-2 divisors).
     *
     * @param array<int, int> $effects  offset => net delta per iteration
     */
    private function genFastPath(array $effects, int $divider): string
    {
        ksort($effects);

        // For power-of-2 divisors, a right-shift is faster than division.
        // For other divisors, use `(int)($d[$i]/D)` rather than `intdiv($d[$i],D)`
        // because `intdiv(a,b)` contains a comma which is an IR input opcode (,)
        // and would be replaced by the read-input code during strtr.
        $isPow2   = ($divider & ($divider - 1)) === 0;
        $shift    = $isPow2 ? (int) log($divider, 2) : 0;
        $quotient = $isPow2
            ? '(($d[$i]??0)>>' . $shift . ')'
            : '(int)(($d[$i]??0)/' . $divider . ')';

        $out = '';

        foreach ($effects as $offset => $delta) {
            if ($delta === 0) {
                continue;
            }

            $ref      = $this->cellRef($offset);
            $absDelta = abs($delta);
            $op       = $delta > 0 ? '+' : '-';

            if ($delta % $divider === 0) {
                // Integer factor: reuse the cheaper $d[$i]*K form.
                $factor = abs(intdiv($delta, $divider));
                $mult   = $factor === 1 ? '($d[$i]??0)' : '($d[$i]??0)*' . $factor;

                if ($this->cellMask !== self::MASK_INT) {
                    $out .= $ref . '=((' . $ref . '??0)' . $op . $mult . ')&' . $this->cellMask . ';';
                } else {
                    $absOp = $factor === 1 ? 'abs($d[$i]??0)' : 'abs($d[$i]??0)*' . $factor;
                    $out .= $ref . '=(' . $ref . '??0)' . $op . $absOp . ';';
                }
            } else {
                // Non-integer factor: runtime quotient required.
                $mult = $absDelta === 1 ? $quotient : $quotient . '*' . $absDelta;

                if ($this->cellMask !== self::MASK_INT) {
                    $out .= $ref . '=((' . $ref . '??0)' . $op . $mult . ')&' . $this->cellMask . ';';
                } else {
                    $rhs = $absDelta === 1 ? $quotient : $quotient . '*' . $absDelta;
                    $out .= $ref . '=(' . $ref . '??0)' . $op . $rhs . ';';
                }
            }
        }

        return $out . '$d[$i]=0;';
    }

    /**
     * Try to collapse a simple loop body (e.g. `[->+<]`) into straight-line
     * cell arithmetic. Falls back to a `while` when optimisation is not possible.
     *
     * When the controller decrements by D > 1 per iteration and some target
     * cells change by a value not divisible by D, a conditional guard is emitted:
     *
     *   if ($d[$i] % D) { W<ir_body>R } else { <fast_path> }
     *
     * `W` is a pseudobytecode for "raw while, no further loop optimisation";
     * the compilation pipeline converts it to `while($d[$i]){` via strtr.
     */
    protected function cyclesOp(string $str): string
    {
        // Bodies containing nested L...R require a separate analyser that can
        // recognise canonical multiplication patterns. Try it first; if it
        // declines, fall back to the flat-body path which (re-)encodes the
        // outer loop as a `while`.
        if (str_contains($str, 'L')) {
            $optimised = $this->tryMulOpt($str);
            if ($optimised !== null) {
                return $optimised;
            }

            return 'L' . $str . 'R';
        }

        // Pointer moves must balance: the body must leave the pointer at its
        // starting position, otherwise the loop condition cell changes each pass.
        $moves = ['m' => 0, 'p' => 0];
        preg_match_all('/(\d{2}|)([mp])/S', $str, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $moves[$match[2]] += $match[1] ?: 1;
        }
        if ($moves['m'] !== $moves['p']) {
            return 'L' . $str . 'R';
        }

        // Loops containing [-] (opcode c) need special handling because the
        // multiply-by-source model no longer applies.  Delegate to a dedicated
        // path; fall back to the standard path when c is absent.
        if (str_contains($str, 'c')) {
            return $this->cyclesOpWithClear($str);
        }

        $analysis = $this->collectLoopEffects($str);
        if ($analysis === null) {
            return 'L' . $str . 'R';
        }

        ['divider' => $divider, 'effects' => $effects] = $analysis;

        // Check whether every per-iteration delta divides evenly by the controller step.
        $allInteger = true;
        foreach ($effects as $delta) {
            if ($delta % $divider !== 0) {
                $allInteger = false;
                break;
            }
        }

        if ($allInteger) {
            return $this->genStraightLine($effects, $divider);
        }

        // Non-integer case: only safe to optimise for bounded cells (8/16-bit)
        // where $d[$i] is always non-negative and the divisibility check is reliable.
        if ($this->cellMask === self::MASK_INT) {
            return 'L' . $str . 'R';
        }

        // Emit a runtime divisibility guard followed by the unconditional fast path:
        //
        //   if (cell NOT divisible by D) { W<ir_body>R }   ← while fallback
        //   <fast_path>                                      ← runs when divisible
        //
        // For bounded cells (8/16-bit), a non-divisible value makes the loop
        // controller never reach 0 (e.g. odd value with [--…] cycles through
        // 255→253→…→1→255→…), so the while is an infinite loop and execution
        // never reaches the fast path.  Therefore the fast path can be placed
        // unconditionally after the if — it only runs when the while was skipped.
        //
        // NOTE: `else` cannot be used here because it contains the character `l`
        // which is an IR opcode and would be mis-compiled by pattern 8.
        // The `W` pseudobytecode marks "while without further loop optimisation".
        $guard    = ($divider & ($divider - 1)) === 0                // power of 2?
            ? '($d[$i]??0)&' . ($divider - 1)                     //   bitwise AND
            : '($d[$i]??0)%' . $divider;
        $fastPath = $this->genFastPath($effects, $divider);

        return 'if(' . $guard . '){W' . $str . 'R}' . $fastPath;
    }

    /**
     * Tokenise an outer loop body, recognising one level of nested L...R as
     * atomic tokens. Returns null if the body cannot be tokenised (mismatched
     * brackets, deeper nesting, truncated count prefix).
     *
     * @return list<array{op: string, num?: int, inner?: string}>|null
     */
    private function parseLoopTokens(string $body): ?array
    {
        $tokens = [];
        $len = strlen($body);
        $k = 0;

        while ($k < $len) {
            $c = $body[$k];

            if ($c >= '0' && $c <= '9') {
                if ($k + 2 >= $len) {
                    return null;
                }
                $num = (int) substr($body, $k, 2);
                $tokens[] = ['op' => $body[$k + 2], 'num' => $num];
                $k += 3;
                continue;
            }

            if ($c === 'L') {
                // Find matching R; only one level of nesting is supported, so
                // the inner body must not contain another `L`.
                $j = $k + 1;
                while ($j < $len && $body[$j] !== 'R') {
                    if ($body[$j] === 'L') {
                        return null;
                    }
                    $j++;
                }
                if ($j >= $len) {
                    return null;
                }
                $tokens[] = ['op' => 'L', 'inner' => substr($body, $k + 1, $j - $k - 1)];
                $k = $j + 1;
                continue;
            }

            $tokens[] = ['op' => $c, 'num' => 1];
            $k++;
        }

        return $tokens;
    }

    /**
     * Analyse a flat (non-nested) loop body that should be a "scatter" loop:
     *   - starts with `M` of count 1 (the controller decrement)
     *   - distributes the source value to other relative offsets via P/M
     *   - all pointer moves balance to a net delta of 0
     *
     * Returns the per-iteration distribution as `['targets' => [offset => factor]]`
     * (factors are signed), or null if the body is not a pure scatter.
     *
     * @return array{targets: array<int, int>}|null
     */
    private function analyseFlatScatter(string $body): ?array
    {
        $tokens = $this->parseLoopTokens($body);
        if ($tokens === null) {
            return null;
        }

        if (count($tokens) < 1 || $tokens[0]['op'] !== 'M' || ($tokens[0]['num'] ?? 0) !== 1) {
            return null;
        }

        $pos = 0;
        $targets = [];

        foreach ($tokens as $idx => $t) {
            if ($idx === 0) {
                continue; // skip controller
            }

            $op = $t['op'];
            $num = $t['num'] ?? 1;

            if ($op === 'p') {
                $pos += $num;
            } elseif ($op === 'm') {
                $pos -= $num;
            } elseif ($op === 'P' || $op === 'M') {
                if ($pos === 0) {
                    return null; // extra source modification breaks the scatter model
                }
                $targets[$pos] = ($targets[$pos] ?? 0) + ($op === 'P' ? $num : -$num);
            } else {
                return null; // L, c, etc. not allowed inside a scatter body
            }
        }

        if ($pos !== 0) {
            return null;
        }

        $targets = array_filter($targets, static fn (int $v): bool => $v !== 0);
        if ($targets === []) {
            return null;
        }

        return ['targets' => $targets];
    }

    /**
     * Recognise the canonical Brainfuck multiplication pattern:
     *
     *   [- moves L_scatter R moves L_gather R moves]
     *
     * Outer body: leading `M` is the controller; pointer moves balance to 0;
     * exactly two nested loops:
     *   1. scatter — clears source B and adds B to two or more targets
     *   2. gather  — moves one of the scatter targets (the temp T) back to B
     *
     * Net effect after `A` outer iterations:
     *   cell[0]            = 0           (controller drained)
     *   cell[B_pos]        unchanged     (cleared by scatter, restored by gather)
     *   cell[non-temp tgt] += A * B      (one iteration adds B; total adds A·B)
     *   cell[T_pos]        = 0           (cleared by gather)
     *
     * Returns optimised straight-line PHP for the entire outer loop, or null
     * if the body does not fit this shape.
     */
    private function tryMulOpt(string $body): ?string
    {
        $tokens = $this->parseLoopTokens($body);
        if ($tokens === null) {
            return null;
        }

        // First token: controller M with count 1.
        if (count($tokens) < 4 || $tokens[0]['op'] !== 'M' || ($tokens[0]['num'] ?? 0) !== 1) {
            return null;
        }

        $pos = 0;
        /** @var array{pos: int, targets: array<int, int>}|null $scatter */
        $scatter = null;
        /** @var array{pos: int, target: int}|null $gather */
        $gather = null;

        $count = count($tokens);
        for ($i = 1; $i < $count; $i++) {
            $t = $tokens[$i];
            $op = $t['op'];
            $num = $t['num'] ?? 1;

            if ($op === 'p') {
                $pos += $num;
                continue;
            }
            if ($op === 'm') {
                $pos -= $num;
                continue;
            }
            if ($op !== 'L') {
                return null; // outer body must contain only moves and inner loops
            }

            $eff = $this->analyseFlatScatter($t['inner'] ?? '');
            if ($eff === null) {
                return null;
            }

            if ($scatter === null) {
                if (count($eff['targets']) < 2) {
                    return null; // a real scatter must distribute to 2+ cells
                }
                $absTargets = [];
                foreach ($eff['targets'] as $rel => $factor) {
                    $absTargets[$pos + $rel] = $factor;
                }
                $scatter = ['pos' => $pos, 'targets' => $absTargets];
                continue;
            }

            if ($gather !== null) {
                return null; // more than two nested loops
            }

            // The gather must be a single-target move-loop (factor 1) whose
            // target is the original scatter source (preserves B), and whose
            // own source position was one of the scatter's targets (the temp).
            if (count($eff['targets']) !== 1) {
                return null;
            }
            $relTarget = array_key_first($eff['targets']);
            $factor = $eff['targets'][$relTarget];
            if ($factor !== 1) {
                return null;
            }
            $absTarget = $pos + $relTarget;
            if (!isset($scatter['targets'][$pos])) {
                return null; // gather source isn't a scatter target → not a temp
            }
            if ($absTarget !== $scatter['pos']) {
                return null; // gather doesn't restore B
            }
            $gather = ['pos' => $pos, 'target' => $absTarget];
        }

        if ($pos !== 0 || $scatter === null || $gather === null) {
            return null;
        }

        // Net effect: every scatter target except the temp accumulates A·B.
        $bPos = $scatter['pos'];
        $tempPos = $gather['pos'];
        $bRefSuffix = $bPos > 0 ? '+' . $bPos : ($bPos === 0 ? '' : (string) $bPos);

        $out = '';
        foreach ($scatter['targets'] as $absPos => $coef) {
            if ($absPos === $tempPos) {
                continue; // restored by gather
            }
            $posStr = $absPos > 0 ? '+' . $absPos : (string) $absPos;
            $factor = $coef === 1 ? '' : ('*' . $coef);
            if ($this->cellMask !== self::MASK_INT) {
                $out .= '$d[$i' . $posStr . ']=(($d[$i' . $posStr . ']??0)+($d[$i]??0)*($d[$i'
                    . $bRefSuffix . ']??0)' . $factor . ')&' . $this->cellMask . ';';
            } else {
                $out .= '$d[$i' . $posStr . ']=($d[$i' . $posStr . ']??0)+(($d[$i]??0)*($d[$i'
                    . $bRefSuffix . ']??0))' . $factor . ';';
            }
        }
        $out .= '$d[$i]=0;';

        return $out;
    }

    /**
     * Handle loop bodies that contain the `c` (i.e. `[-]`) opcode.
     *
     * Two patterns are recognised and optimised; everything else falls back to
     * a `while` loop so correctness is preserved.
     *
     * Class A — one-shot:
     *   `c` appears at pos=0 at any point ⇒ the outer loop runs at most once.
     *   Emitted as: if($d[$i]){ <raw ops on other cells>; $d[$i]=0; }
     *
     * Class B — constant-set:
     *   `M` at pos=0 is the usual decrement controller AND every non-zero
     *   position that is written was first cleared by `c` in the same iteration
     *   ⇒ the destination ends up at a constant value regardless of the source.
     *   Emitted as: if($d[$i]){ dest=const; …; $d[$i]=0; }
     */
    private function cyclesOpWithClear(string $str): string
    {
        $len = strlen($str);

        // First pass: determine control type.
        $pos = 0;
        $hasClearAtZero = false;
        $hasDecrAtZero  = false;

        for ($k = 0; $k < $len; $k++) {
            if ((string) (int) $str[$k] === $str[$k]) {
                $num = (int) substr($str, $k++, 2);
                $op  = $str[++$k];
            } else {
                $num = 1;
                $op  = $str[$k];
            }
            if ($op === 'p') {
                $pos += $num;
            } elseif ($op === 'm') {
                $pos -= $num;
            } elseif ($op === 'c' && $pos === 0) {
                $hasClearAtZero = true;
            } elseif ($op === 'M' && $pos === 0) {
                $hasDecrAtZero = true;
            }
        }

        if ($hasClearAtZero) {
            return $this->oneShotOpt($str, $len);
        }

        if ($hasDecrAtZero) {
            return $this->constantSetOpt($str, $len);
        }

        return 'L' . $str . 'R';
    }

    /**
     * Class A: c at pos=0 somewhere in the body ⇒ the loop runs at most once.
     *
     * Generates: if($d[$i]){ <one occurrence of each non-pos0 op>; $d[$i]=0; }
     * Operations at pos=0 are skipped because the trailing `$d[$i]=0` dominates.
     */
    private function oneShotOpt(string $str, int $len): string
    {
        $out = '';
        $pos = 0;

        for ($k = 0; $k < $len; $k++) {
            if ((string) (int) $str[$k] === $str[$k]) {
                $num = (int) substr($str, $k++, 2);
                $op  = $str[++$k];
            } else {
                $num = 1;
                $op  = $str[$k];
            }

            if ($op === 'p') {
                $pos += $num;
                continue;
            }
            if ($op === 'm') {
                $pos -= $num;
                continue;
            }
            if ($pos === 0) {
                continue;
            } // overridden by final $d[$i]=0

            $posStr = $pos > 0 ? '+' . $pos : (string) $pos;
            $ref    = '$d[$i' . $posStr . ']';

            $out .= match ($op) {
                'P'     => $this->wrapCell($ref, '+', $num),
                'M'     => $this->wrapCell($ref, '-', $num),
                'c'     => $ref . '=0;',
                default => '',
            };
        }

        return 'if($d[$i]??0){' . $out . '$d[$i]=0;}';
    }

    /**
     * Class B: M at pos=0 is the decrement controller and every non-zero
     * position that is written was first cleared by `c` in the same iteration.
     *
     * Each such destination ends up holding a constant value (the net sum of
     * P/M ops since the last `c` at that position), independent of the source.
     *
     * Generates: if($d[$i]){ dest=const; …; $d[$i]=0; }
     *
     * Falls back to a while loop if any non-zero position is written without a
     * preceding `c` (that would be a multiply-accumulate, not a constant-set).
     */
    private function constantSetOpt(string $str, int $len): string
    {
        $pos              = 0;
        $cleared          = [];  // positions cleared by c in this iteration
        $constants        = [];  // pos => net constant value

        for ($k = 0; $k < $len; $k++) {
            if ((string) (int) $str[$k] === $str[$k]) {
                $num = (int) substr($str, $k++, 2);
                $op  = $str[++$k];
            } else {
                $num = 1;
                $op  = $str[$k];
            }

            if ($op === 'p') {
                $pos += $num;
                continue;
            }
            if ($op === 'm') {
                $pos -= $num;
                continue;
            }
            if ($op === 'M' && $pos === 0) {
                continue;
            } // source decrement, handled

            // Any other op at pos=0 is not supported (e.g. P at pos=0 alongside M).
            if ($pos === 0) {
                return 'L' . $str . 'R';
            }

            if ($op === 'c') {
                $cleared[$pos]   = true;
                $constants[$pos] = 0;
            } elseif ($op === 'P' || $op === 'M') {
                if (!isset($cleared[$pos])) {
                    // Multiply-accumulate at a non-cleared position: not supported here.
                    return 'L' . $str . 'R';
                }
                $constants[$pos] += $op === 'P' ? $num : -$num;
            }
        }

        if ($constants === []) {
            return 'L' . $str . 'R';
        }

        $out = '';
        foreach ($constants as $constPos => $value) {
            if ($this->cellMask !== self::MASK_INT) {
                $value &= $this->cellMask;
            }
            $posStr = $constPos > 0 ? '+' . $constPos : (string) $constPos;
            $out   .= '$d[$i' . $posStr . ']=' . $value . ';';
        }

        return 'if($d[$i]??0){' . $out . '$d[$i]=0;}';
    }

    /**
     * Apply all pattern-based transformations to the internal opcode string,
     * then translate the remaining symbols to PHP.
     */
    protected function compileCode(string $str): string
    {
        $patterns = [
            // First pass: outer loops with one level of nesting.
            // Catches multiplication patterns like [->[->+>+<<]>>[-<<+>>]<<<]
            // before the inner [->+>+<<] loops are converted to PHP. cyclesOp
            // detects the `L` opcode in the body and dispatches to tryMulOpt.
            // If it can't optimise, it returns `L...R` unchanged so the second
            // pass can still process the inner loops as while-loops.
            '/L((?:[MPmpc\d]|L[MPmpc\d]+R)+)R/'
                => fn (array $m): string => $this->cyclesOp(self::pcreGroup($m, 1)),

            // Second pass: innermost flat loops (no nested L/R).
            // [>>>+<<-<] → straight-line arithmetic.
            '/L([MPmpc\d]+)R/' => fn (array $m): string => $this->cyclesOp(self::pcreGroup($m, 1)),

            // [-]+N, [-]-N — cell is known zero after clear; fold into a direct constant assignment.
            '/c(\d{2}|)([PM])/' => fn (array $m): string => '$d[$i]=' . (
                $this->cellMask === self::MASK_INT
                    ? ((self::pcreGroup($m, 2) === 'P' ? 1 : -1)
                        * (self::pcreGroup($m, 1) !== '' ? (int) self::pcreGroup($m, 1) : 1))
                    : (((self::pcreGroup($m, 2) === 'P' ? 1 : -1)
                        * (self::pcreGroup($m, 1) !== '' ? (int) self::pcreGroup($m, 1) : 1))
                        & $this->cellMask)
            ) . ';',

            // <+++>, <[-]>, <--->
            '/(\d{2}|(?<!\d))(m)(\d{2}|)([McP])\\1p/' =>
                fn (array $m): string => $this->dirOp(
                    self::pcreGroup($m, 2),
                    self::pcreGroup($m, 1),
                    self::pcreGroup($m, 3),
                    self::pcreGroup($m, 4),
                ),

            // >+++<, >[-]<, >---<
            '/(\d{2}|(?<!\d))(p)(\d{2}|)([McP])\\1m/' =>
                fn (array $m): string => $this->dirOp(
                    self::pcreGroup($m, 2),
                    self::pcreGroup($m, 1),
                    self::pcreGroup($m, 3),
                    self::pcreGroup($m, 4),
                ),

            // ++>>, <<<-
            '/(\d{2}|(?<!\d))([MP])([mp])/' => fn (array $m): string => $this->op(
                self::pcreGroup($m, 1),
                self::pcreGroup($m, 2),
                self::pcreGroup($m, 3) === 'm' ? '$i--' : '$i++',
            ),

            // <<+, >>>-, >>>[-]
            '/(\d{2}|(?<!\d))([pm])(\d{2}|)([PMc])/' =>
                fn (array $m): string => $this->op(
                    self::pcreGroup($m, 3),
                    self::pcreGroup($m, 4),
                    rtrim($this->op(self::pcreGroup($m, 1), self::pcreGroup($m, 2)), ';'),
                ),

            // ++, ---, [-], [<], [>], <<<, >>>
            '/(\d{2}|)([MPmplrc])/' => fn (array $m): string => $this->op(self::pcreGroup($m, 1), self::pcreGroup($m, 2)),
        ];

        foreach ($patterns as $pattern => $callback) {
            $str = preg_replace_callback($pattern, $callback, $str) ?? '';
        }

        $readInput = '(array_shift($in)??0)&' . $this->cellMask;

        $map = [
            'E' => 'echo chr(($d[$i]??0)&' . self::MASK_BYTE . ');',
            'l' => 'for(;$d[$i]??0;--$i);',
            'r' => 'for(;$d[$i]??0;++$i);',
            ',' => 'if(!$in){$in=array_values(unpack("c*",fgets(STDIN)??""));$in[]=0;};$d[$i]=' . $readInput . ';',
            'L' => 'while($d[$i]??0){',
            'W' => 'while($d[$i]??0){', // raw while: no loop optimisation attempted
            'R' => '}',
            '#' => 'echo "$i: ".($d[$i]??0)."\n";',
        ];
        if ($this->brainfork) {
            $map['Y'] = '$pid=pcntl_fork();if($pid)$d[$i]=0;else $d[++$i]=1;';
        }

        $str = strtr($str, $map);

        return $str;
    }
}
