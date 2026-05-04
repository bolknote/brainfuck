<?php

declare(strict_types=1);

namespace BolkNote\Brainfuck;

class Compiler
{
    private const int TAPE_SIZE = 65535 * 2;
    private const int MAX_REPEAT = 99;

    /** Bitmask applied to every cell write: 0xFF for 8-bit, 0xFFFF for 16-bit, 0 = no wrap. */
    private readonly int $cellMask;

    /**
     * @param int $cellBits Cell width in bits.
     *                       8  — standard BF (default): cells wrap at 256.
     *                       16 — extended BF: cells wrap at 65536.
     *                       0  — no wrapping: cells are unbounded PHP integers.
     */
    public function __construct(int $cellBits = 8)
    {
        $this->cellMask = match ($cellBits) {
            8       => 0xFF,
            16      => 0xFFFF,
            0       => 0,
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
        $str = '$d=array_fill(0,' . self::TAPE_SIZE . ',0);$i=' . $tapeStart . ';' . $str;

        $codes = $input === '' ? [] : (unpack('c*', $input . "\0") ?: []);

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
        $str = preg_replace("/[^\-+\[\]><,.#Y]+/", '', $str) ?? '';

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
        return preg_replace_callback(
            '/([PMpm])(\\1{1,' . (self::MAX_REPEAT - 1) . '})/',
            static fn ($m) => sprintf('%02d%s', strlen($m[2]) + 1, $m[1]),
            $str,
        ) ?? '';
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
        if ($this->cellMask === 0) {
            return $amount === 1
                ? $ref . ($op === '+' ? '++;' : '--;')
                : $ref . $op . '=' . $amount . ';';
        }

        return $ref . '=(' . $ref . $op . $amount . ')&' . $this->cellMask . ';';
    }

    /**
     * Resolve `*(N)` placeholders left by cyclesOp, scaling them by $divider.
     */
    protected function applyDivider(string $out, int $divider): string
    {
        $divider = $divider ?: 1;

        return preg_replace_callback('/\*\((\d+)\)/S', static function ($m) use ($divider) {
            $count = (int) $m[1];

            if ($count === $divider) {
                return '';
            }

            if ($count % $divider === 0) {
                return '*' . intdiv($count, $divider);
            }

            return '*' . ($count / $divider);
        }, $out) ?? $out;
    }

    /**
     * Try to collapse a simple loop body (e.g. `[->+<]`) into straight-line
     * cell arithmetic. Falls back to a `while` when optimisation is not possible.
     */
    protected function cyclesOp(string $str): string
    {
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

        $out = '';
        $pos = 0;
        $start = false;
        $divider = 0;

        $len = strlen($str);
        for ($k = 0; $k < $len; $k++) {
            if ((string) (int) $str[$k] === $str[$k]) {
                $num = (int) substr($str, $k++, 2);
                $op = $str[++$k];
            } else {
                $num = 1;
                $op = $str[$k];
            }

            $posStr = match (true) {
                $pos > 0  => '+' . $pos,
                $pos === 0 => '',
                default   => (string) $pos,
            };

            switch ($op) {
                case 'm':
                    $pos -= $num;
                    break;

                case 'p':
                    $pos += $num;
                    break;

                case 'M':
                case 'P':
                    if ($start || $pos !== 0) {
                        $operator = $op === 'M' ? '-' : '+';
                        $ref = '$d[$i' . $posStr . ']';

                        if ($this->cellMask) {
                            // With wrapping: cells are always ≥ 0, abs() is not needed.
                            $out .= $ref . '=(' . $ref . $operator . '$d[$i]*(' . $num . '))&' . $this->cellMask . ';';
                        } else {
                            // Without wrapping: abs() guards against negative source cells.
                            $out .= $ref . $operator . '=abs($d[$i])*(' . $num . ');';
                        }
                    } else {
                        $start = true;
                        $divider += $num;
                        $out = $this->applyDivider($out, $divider);
                    }
                    break;
            }
        }

        if ($start) {
            $out .= '$d[$i]=0;';
            return $this->applyDivider($out, $divider);
        }

        return 'L' . $str . 'R';
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

        return 'if($d[$i]){' . $out . '$d[$i]=0;}';
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
            if ($this->cellMask) {
                $value &= $this->cellMask;
            }
            $posStr = $constPos > 0 ? '+' . $constPos : (string) $constPos;
            $out   .= '$d[$i' . $posStr . ']=' . $value . ';';
        }

        return 'if($d[$i]){' . $out . '$d[$i]=0;}';
    }

    /**
     * Apply all pattern-based transformations to the internal opcode string,
     * then translate the remaining symbols to PHP.
     */
    protected function compileCode(string $str): string
    {
        $patterns = [
            // [>>>+<<-<]  — loop optimisation
            '/L([MPmpc\d]+)R/' => fn ($m) => $this->cyclesOp($m[1]),

            // <+++>, <[-]>, <--->
            '/(\d{2}|(?<!\d))(m)(\d{2}|)([McP])\\1p/' =>
                fn ($m) => $this->dirOp($m[2], $m[1], $m[3], $m[4]),

            // >+++<, >[-]<, >---<
            '/(\d{2}|(?<!\d))(p)(\d{2}|)([McP])\\1m/' =>
                fn ($m) => $this->dirOp($m[2], $m[1], $m[3], $m[4]),

            // ++>>, <<<-
            '/(\d{2}|(?<!\d))([MP])([mp])/' =>
                fn ($m) => $this->op($m[1], $m[2], $m[3] === 'm' ? '$i--' : '$i++'),

            // <<+, >>>-, >>>[-]
            '/(\d{2}|(?<!\d))([pm])(\d{2}|)([PMc])/' =>
                fn ($m) => $this->op($m[3], $m[4], rtrim($this->op($m[1], $m[2]), ';')),

            // ++, ---, [-], [<], [>], <<<, >>>
            '/(\d{2}|)([MPmplrc])/' => fn ($m) => $this->op($m[1], $m[2]),
        ];

        foreach ($patterns as $pattern => $callback) {
            $str = preg_replace_callback($pattern, $callback, $str) ?? '';
        }

        $readInput = $this->cellMask
            ? 'array_shift($in)&' . $this->cellMask
            : 'array_shift($in)';

        return strtr($str, [
            'E' => 'echo chr($d[$i]&255);',
            'l' => 'for(;$d[$i];--$i);',
            'r' => 'for(;$d[$i];++$i);',
            ',' => 'if(!$in){$in=array_values(unpack("c*",rtrim(fgets(STDIN))));$in[]=0;};$d[$i]=' . $readInput . ';',
            'L' => 'while($d[$i]){',
            'R' => '}',
            '#' => 'echo "$i: $d[$i]\n";',
            'Y' => '$pid=pcntl_fork();if($pid)$d[$i++]=0;else $d[$i]=1;',
        ]);
    }
}
