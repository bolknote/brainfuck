<?php
declare(strict_types=1);

class Processing_BF
{
    /**
     * Program compiling
     * @param string $str BF program code
     * @param string $input input data of BF program
     * @return string PHP code
     */
    public function compile(string $str, string $input = ''): string
    {
        return $this->addHeader($this->toPHP($str), $input);
    }

    /**
     * Add standard header to compiled BF program
     * @param string $str compiled BF program
     * @param string $input input data of BF program
     *
     * @return string PHP code
     *
     */
    public function addHeader(string $str, string $input = ''): string
    {
        $str = '$d=array_fill(0, 65535 * 2, 0); $i=count($d)/2; ' . $str;

        // If input isn't empty we convert it to array of char codes
        $codes = $input === '' ? [] : unpack('c*', $input . "\0");

        return '$in=[' . implode(', ', $codes) . ']; ' . $str;
    }

    /**
     * Compile program to PHP
     * @param string $str BF program code
     *
     * @return string PHP code
     *
     */
    public function toPHP(string $str): string
    {
        return $this->_compile($this->_prepare($str));
    }

    /**
     * Preparing to compiling and starting.
     * @param string $str program code
     *
     * @return string prepared program code
     *
     */
    protected function _prepare(string $str): string
    {
        // Remove trash chars
        $str = preg_replace("/[^\-+\[\]><,.]+/", '', $str);

        // Escaping opcodes
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

        // Remove useless first cycle
        $str = preg_replace('/^[lrcmp]* L ( ( (?>[^LR]+) | (?R) )* ) R/x', '', $str);

        // group + and -, > and <
        foreach (['MP', 'mp'] as $set) {
            $str = preg_replace_callback("/[$set]{2,}/S",
                static function ($m) {
                    $freq = count_chars($m[0], 1);
                    if (count($freq) === 2) {
                        arsort($freq);

                        $winner = chr(key($freq));
                        $diff = current($freq) - end($freq);

                        if ($diff) {
                            return str_repeat($winner, $diff);
                        }

                        return '';
                    }

                    return $m[0];
                },
                $str);
        }

        // repeating opcodes
        return preg_replace_callback('/([PMpm])(\\1{1,98})/', // Callback for repeating opcodes replacement
            // sq. length
            static fn($m) => sprintf('%02d%s', strlen($m[2]) + 1, $m[1]), $str);
    }

    /**
     * Complex opcodes transformation
     * @param string $dir move direction
     * @param string|int $shift number of movement
     * @param string $col number for sub or add
     * @param string $op operation - sub (M) or add (P)
     *
     * @return string prepared program code
     */
    protected function _dir_op(string $dir, string|int $shift, string $col, string $op): string
    {
        $dir = $dir === 'm' ? '-' : '+';
        $shift = $shift ? (int)$shift : 1;

        $shift = '$i' . $dir . $shift;

        if ($op === 'c') {
            $op = '=0';
        } else {
            $op = $op === 'M' ? '-' : '+';

            if ($col) {
                $op .= '=' . (int)$col;
            } else {
                $op .= $op;
            }
        }

        return '$d[' . $shift . ']' . $op . ';';
    }

    /**
     * Simple opcodes transformation
     * @param string|int $repeat operation repeat factor
     * @param string $op opcode
     * @param string|bool $idx optional data index string
     *
     * @return string prepared program code
     */
    protected function _op(string|int $repeat, string $op, string|bool $idx = false): string
    {
        $idx = $idx === false ? '$d[$i]' : '$d[' . $idx . ']';

        $repeat = (int)$repeat;

        if ($repeat) {
            switch ($op) {
                case 'M':
                    return $idx . '-=' . $repeat . ';';
                case 'P':
                    return $idx . '+=' . $repeat . ';';
                case 'c':
                    return $idx . '=0;';
                case 'm':
                    return '$i-=' . $repeat . ';';
                case 'p':
                    return '$i+=' . $repeat . ';';
            }
        } else {
            switch ($op) {
                case 'M':
                    return $idx . '--;';
                case 'P':
                    return $idx . '++;';
                case 'c':
                    return $idx . '=0;';
                case 'm':
                    return '--$i;';
                case 'p':
                    return '++$i;';
            }
        }

        return str_repeat($op, 1 + $repeat);
    }

    /**
     * Apply loop divider to code
     * @param string $out code string
     * @param int $divider divider value (0 = no divider)
     *
     * @return string prepared program code
     *
     */
    protected function _apply_divider(string $out, int $divider): string
    {
        $divider = $divider ?: 1;

        return preg_replace_callback('/\*\((\d+)\)/S', static function ($m) use ($divider) {
            $num = $m[1] / $divider;

            if ($num === 1) {
                return '';
            }

            return $num === (float)(int)$num ? '*' . $num : '/' . (1 / $num);
        }, $out);
    }

    /**
     * Cycles optimization
     * @param string $str string to optimization
     *
     * @return string prepared program code
     *
     */
    protected function _cycles_op(string $str): string
    {
        // Is loop entry point concur with exit point?
        $braces = ['m' => 0, 'p' => 0];

        preg_replace_callback('/(\d{2}|)([mp])/S',
            static function ($m) use (&$braces) {
                $braces[$m[2]] += $m[1] ?: 1;
            },
            $str);

        if ($braces['m'] !== $braces['p']) {
            return 'L' . $str . 'R';
        }

        // Execution emulation

        $out = '';
        $pos = 0;
        $start = false;
        $clear_end = true;
        $divider = 0;

        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            if ((string)(int)$str[$i] === $str[$i]) {
                $num = (int)substr($str, $i++, 2);
                $op = $str[++$i];
            } else {
                $num = 1;
                $op = $str[$i];
            }

            if ($pos > 0) {
                $pos = '+' . $pos;
            } elseif (!$pos) {
                $pos = '';
            }

            switch ($op) {
                case 'm':
                    $pos = (int)$pos - $num;
                    break;

                case 'p':
                    $pos = (int)$pos + $num;
                    break;

                case 'M':
                case 'P':
                    if ($start || $pos) {
                        $op = $op === 'M' ? '-' : '+';

                        $out .= '$d[$i' . $pos . ']' . $op . '=abs($d[$i])*(' . $num . ');';
                    } else {
                        $start = $pos === 0;

                        if ($start) {
                            $divider += $num;
                            $out = $this->_apply_divider($out, $divider);
                        }
                    }
                    break;

                case 'c':
                    if ($pos) {
                        $out .= 'if ($d[$i]) $d[$i' . $pos . ']=0;';
                    } else {
                        $out .= '$d[$i]=0;';
                        $start = true;
                        $clear_end = false;
                    }

            }
        }

        if ($start) {
            if ($clear_end) {
                $out .= '$d[$i]=0;';
            }

            return $this->_apply_divider($out, $divider);
        }

        return 'L' . $str . 'R';
    }

    /**
     * Main compile routine
     * @param string $str program data
     *
     * @return string PHP program code
     *
     */
    protected function _compile(string $str): string
    {
        $repl = [
            // [>>>+<<-<]
            '/L([MPmpc\d]+)R/' => fn($m) => $this->_cycles_op($m[1]),

            // <+++>, <[-]>, <--->
            '/(\d{2}|(?<!\d))(m)(\d{2}|)([McP])\\1p/' =>
                fn($m) => $this->_dir_op($m[2], $m[1], $m[3], $m[4]),

            // >+++<, >[-]<. >---<
            '/(\d{2}|(?<!\d))(p)(\d{2}|)([McP])\\1m/' =>
                fn($m) => $this->_dir_op($m[2], $m[1], $m[3], $m[4]),

            // ++>>, <<<-
            '/(\d{2}|(?<!\d))([MP])([mp])/' =>
                fn($m) => $this->_op($m[1], $m[2], $m[3] === "m" ? '$i--' : '$i++'),

            // <<+, >>>-, >>>[-]
            '/(\d{2}|(?<!\d))([pm])(\d{2}|)([PMc])/' =>
                fn($m) => $this->_op($m[3], $m[4], rtrim($this->_op($m[1], $m[2]), ";")),

            // ++, ---, [-], [<], [>], <<<, >>>
            '/(\d{2}|)([MPmplrc])/' => fn($m) => $this->_op($m[1], $m[2]),
        ];

        foreach ($repl as $pattern => $func) {
            $str = preg_replace_callback($pattern, $func, $str);
        }

        $trans = [
            'E' => 'printf("%c", $d[$i]);',
            'l' => 'for (;$d[$i];--$i);',
            'r' => 'for (;$d[$i];++$i);',
            ',' => 'if (!$in) { $in = array_values(unpack("c*", rtrim(fgets(STDIN)))); $in[]=0; }; ' .
                '$d[$i] = array_shift($in);',
            'L' => 'while ($d[$i]) {',
            'R' => '}',
            '#' => 'echo "$i: $d[$i]\n";',
            'Y' => '$pid = pcntl_fork(); if ($pid) $d[$i++] = 0; else $d[$i] = 1;',
        ];

        return strtr($str, $trans);
    }
}
