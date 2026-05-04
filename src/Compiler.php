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
        $str = '$d=array_fill(0,' . self::TAPE_SIZE . ',0);$i=intdiv(count($d),2);' . $str;

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

        // Pointer-only operations — no cell modification.
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

        // Cell increment / decrement — may need wrapping and pointer separation.
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
        // Any nested [-] (c) in the body makes the multiply-by-source model invalid:
        //  c at pos=0  → loop runs at most once (not source times)
        //  c at pos≠0  → destination is reset each iteration, so adding source_value
        //                 is wrong (effective coefficient is 1, not source)
        if (str_contains($str, 'c')) {
            return 'L' . $str . 'R';
        }

        $moves = ['m' => 0, 'p' => 0];

        preg_match_all('/(\d{2}|)([mp])/S', $str, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $moves[$match[2]] += $match[1] ?: 1;
        }

        if ($moves['m'] !== $moves['p']) {
            return 'L' . $str . 'R';
        }

        $out = '';
        $pos = 0;
        $start = false;
        $clearEnd = true;
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

                case 'c':
                    // The str_contains('c') guard at entry already catches c at pos=0.
                    // Reaching here means pos≠0: emit a conditional destination clear.
                    if ($pos !== 0) {
                        $out .= 'if ($d[$i]) $d[$i' . $posStr . ']=0;';
                    }
                    break;
            }
        }

        if ($start) {
            if ($clearEnd) {
                $out .= '$d[$i]=0;';
            }

            return $this->applyDivider($out, $divider);
        }

        return 'L' . $str . 'R';
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
            'E' => 'printf("%c",$d[$i]);',
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
