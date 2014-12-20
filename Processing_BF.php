<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4: */
/**
 *
 * Optimizing compilator from Brainfuck to PHP
 *
 * PHP version 5.3+
 *
 * LICENSE: This source file is subject to version 3.0 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_0.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @category   Processing
 * @package    Processing_BF
 * @author     Evgeny Stepanischev <imbolk@gmail.com>
 * @copyright  2005-2013 Evgeny Stepanischev
 * @license    http://www.php.net/license/3_0.txt  PHP License 3.0
 * @version    1.1
 */

// {{{ class Processing_BF

class Processing_BF
{
    // {{{ compile()
    /**
     * Program compling
     * @param string $str   BF program code
     * @param string $input input data of BF program
     *
     * @return string PHP code
     *
     */
    public function compile($str, $input = '')
    {
        return $this->addHeader($this->toPHP($str), $input);
    }
    // }}}

    // {{{ addHeader()
    /**
     * Add standart header to compiled BF program
     * @param string $str   compiled BF program
     * @param string $input input data of BF program
     *
     * @return string PHP code
     *
     */
    public function addHeader($str, $input = '')
    {
        $str = "\$d = array_fill(-65535, 65535, \$i = 0);\n".$str;

        // If input isn't empty we convert it to array of charcodes
        $codes = $input == '' ? [] : unpack('c*', $input);
        $size  = count($codes);

        // End of string in BF
        $codes[] = '$p=0';

        return "\$size=$size; \$in=[".implode(', ', $codes).'];'.$str;
    }
    // }}}

    // {{{ toPHP()
    /**
     * Compile program to PHP
     * @param string $str BF program code
     *
     * @return string PHP code
     *
     */
    public function toPHP($str)
    {
        return $this->_compile($this->_prepare($str));
    }
    // }}}

    // {{{ _prepare()

    /**
     * Preparing to compiling and starting.
     * @param string $str program code
     *
     * @return string prepared program code
     *
     */
    protected function _prepare($str)
    {
        // Remove trash chars
        $str = preg_replace('/[^\-\+\[\]><\,\.]/', '', $str);

        // Escaping opcodes
        $trans = [
            '[<]' => 'l',
            '[>]' => 'r',
            '[-]' => 'c',
            '[+]' => 'c',
            '+'   => 'P',
            '-'   => 'M',
            '<'   => 'm',
            '>'   => 'p',
            '['   => 'L',
            ']'   => 'R',
        ];

        $str = strtr($str, $trans);

        // group + and -, > and <
        foreach (['MP', 'mp'] as $set) {
            $str = preg_replace_callback("/[$set]{2,}/",
                function ($m) {
                    $freq = count_chars($m[0], 1);
                    if (count($freq) == 2) {
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
        $result = preg_replace_callback('/([PMpm])(\\1{1,98})/', function ($m) {
            // Callback for repeating opcodes replacement
            // sq. length
            $len = strlen($m[2]) + 1;
            if ($len < 10) {
                $len = '0'.$len;
            }

            return $len.$m[1];
        }, $str);

        return $result;
    }
    // }}}
    // {{{ _dir_op

    /**
     * Complex opcodes transformation
     * @param string $dir   move direction
     * @param int    $shift number of movement
     * @param int    $col   number for sub or add
     * @param string $op    operation - sub (M) or add (P)
     *
     * @return string prepared program code
     *
     */
    protected function _dir_op($dir, $shift, $col, $op)
    {
        $dir   = $dir == 'm' ? '-' : '+';
        $shift = $shift ? (int) $shift : 1;

        $shift = '$i'.$dir.intval($shift);

        if ($op == 'c') {
            $op = '=0';
        } else {
            $op = $op == 'M' ? '-' : '+';

            if ($col) {
                $op = $op.'='.(int) $col;
            } else {
                $op = $op.$op;
            }
        }

        return '$d['.$shift.']'.$op.';';
    }
    // }}}
    // {{{ _op()

    /**
     * Simple opcodes transformation
     * @param int $repeat operation repeat factor
     * @param int $op     opcode
     * @param int $idx    optional data index string
     *
     * @return string prepared program code
     *
     */
    protected function _op($repeat, $op, $idx = false)
    {
        $idx = $idx === false ? '$d[$i]' : '$d['.$idx.']';

        if ($repeat = (int) $repeat) {
            switch ($op) {
               case 'M':
                   return $idx.'-='.$repeat.';';
               case 'P':
                   return $idx.'+='.$repeat.';';
               case 'c':
                   return $idx.'=0;';
               case 'm':
                   return '$i-='.$repeat.';';
               case 'p':
                   return '$i+='.$repeat.';';
           }
        } else {
            switch ($op) {
               case 'M':
                   return $idx.'--;';
               case 'P':
                   return $idx.'++;';
               case 'c':
                   return $idx.'=0;';
               case 'm':
                   return '--$i;';
               case 'p':
                   return '++$i;';
           }
        }

        return str_repeat($op, 1 + (int) $repeat);
    }
    // }}}
    // {{{ _cycles_op

    /**
     * Cycles optimization
     * @param string $str string to optimization
     *
     * @return string prepared program code
     *
     */
    protected function _cycles_op($str)
    {
        // Is loop entry point concur with exit point?
        $brack = ['m' => 0, 'p' => 0];

        preg_replace_callback('/(\d{2}|)([mp])/',
            function ($m) use (&$brack) {
                $brack[$m[2]] += $m[1] ? $m[1] : 1;
            },
        $str);

        if ($brack['m'] != $brack['p']) {
            return 'L'.$str.'R';
        }

        // Execution emulation

        $out = '';
        $pos = 0;
        $start = false;
        $clear_end = true;

        $len = strlen($str);
        for ($i = 0; $i<$len; $i++) {
            if (is_numeric($str{$i})) {
                $num = intval(substr($str, $i++, 2));
                $op  = $str{++$i};
            } else {
                $num = 1;
                $op  = $str{$i};
            }

            if ($pos > 0) {
                $pos = '+'.(int) $pos;
            } elseif (!$pos) {
                $pos = '';
            }

            switch ($op) {
                case 'm':
                    $pos -= $num;
                    break;

                case 'p':
                    $pos += $num;
                    break;

                case 'M':
                case 'P':
                    if ($start || $pos) {
                        $op  = $op == 'M' ? '-' : '+';
                        $num = $num == 1 ? '' : '*'.$num;

                        $out .= '$d[$i'.$pos.']'.$op.'=$d[$i]'.$num.';';
                    } else {
                        $start = $pos == 0;
                    }
                    break;

                case 'c':
                    if ($pos) {
                        $out .= 'if ($d[$i]) $d[$i'.$pos.']=0;';
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

            return $out;
        }

        return 'L'.$str.'R';
    }
    // }}}
    // {{{ _compile()

    /**
     * Main complile routine
     * @param string $str program data
     *
     * @return string PHP program code
     *
     */
    protected function _compile($str)
    {
        $repl = [
            // [>>>+<<-<]
            '/L([MPmpc\d]+)R/' => function ($m) { return $this->_cycles_op($m[1]); },

            // <+++>, <[-]>, <--->
            '/(\d{2}|(?<!\d))(m)(\d{2}|)([McP])\\1p/' => function ($m) { return $this->_dir_op($m[2], $m[1], $m[3], $m[4]); },

            // >+++<, >[-]<. >---<
            '/(\d{2}|(?<!\d))(p)(\d{2}|)([McP])\\1m/' => function ($m) { return $this->_dir_op($m[2], $m[1], $m[3], $m[4]); },

            // ++>>, <<<-
            '/(\d{2}|(?<!\d))([MP])([mp])/' => function ($m) { return $this->_op($m[1], $m[2], $m[3] === "m" ? '$i--' : '$i++'); },

            // <<+, >>>-, >>>[-]
            '/(\d{2}|(?<!\d))([pm])(\d{2}|)([PMc])/' => function ($m) { return $this->_op($m[3], $m[4], rtrim($this->_op($m[1], $m[2]), ";")); },

            // ++, ---, [-], [<], [>], <<<, >>>
            '/(\d{2}|)([MPmplrc])/' => function ($m) { return $this->_op($m[1], $m[2]); },
        ];

        foreach ($repl as $pattern => $func) {
            $str = preg_replace_callback($pattern, $func, $str);
        }

        $trans = [
            '.'  => 'echo chr($d[$i]);',
            'l'  => 'for (;$d[$i];--$i);',
            'r'  => 'for (;$d[$i];++$i);',
            ','  => 'if ($p < $size) $d[$i] = $in[$p++]; else '.
                    '{$in = array_values(unpack("c*", file_get_contents("php://stdin")));$in[]=0;$d[$i] = $in[$p=0];}',
            'L'  => 'while ($d[$i]) {',
            'R'  => '}',
        ];

        return strtr($str, $trans);
    }
     // }}}

    // }}}
}
// }}}
;
