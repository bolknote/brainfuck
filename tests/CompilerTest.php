<?php

declare(strict_types=1);

namespace BolkNote\Brainfuck\Tests;

use BolkNote\Brainfuck\Compiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CompilerTest extends TestCase
{
    private Compiler $compiler;

    private static function readSample(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read sample file: {$path}");
        }

        return $contents;
    }

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    private function execute(string $bf, string $input = ''): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            eval($this->compiler->compile($bf, $input));
            $out = ob_get_clean();

            return is_string($out) ? $out : '';
        } finally {
            if (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /**
     * Minimal reference BF interpreter (no optimisation, strict 8-bit cells).
     * Used to cross-check compiler output against ground truth.
     */
    private function naive(string $bf, string $input = ''): string
    {
        $code = preg_replace('/[^><+\-.,\[\]]/', '', $bf) ?? '';
        $len  = strlen($code);
        $tape = array_fill(0, 65536, 0);
        $ptr  = 0;
        $ip   = 0;
        $out  = '';

        while ($ip < $len) {
            switch ($code[$ip]) {
                case '>': ++$ptr;
                    break;
                case '<': --$ptr;
                    break;
                case '+': $tape[$ptr] = ($tape[$ptr] + 1) & 255;
                    break;
                case '-': $tape[$ptr] = ($tape[$ptr] - 1) & 255;
                    break;
                case '.': $out .= chr($tape[$ptr]);
                    break;
                case ',':
                    $tape[$ptr] = $input !== '' ? ord($input[0]) & 255 : 0;
                    $input = substr($input, 1);
                    break;
                case '[':
                    if ($tape[$ptr] === 0) {
                        $depth = 1;
                        while ($depth > 0) {
                            $c = $code[++$ip];
                            if ($c === '[') {
                                ++$depth;
                            } elseif ($c === ']') {
                                --$depth;
                            }
                        }
                    }
                    break;
                case ']':
                    if ($tape[$ptr] !== 0) {
                        $depth = 1;
                        while ($depth > 0) {
                            $c = $code[--$ip];
                            if ($c === ']') {
                                ++$depth;
                            } elseif ($c === '[') {
                                --$depth;
                            }
                        }
                    }
                    break;
            }
            ++$ip;
        }

        return $out;
    }

    public function testOutputSingleChar(): void
    {
        $this->assertSame('A', $this->execute(str_repeat('+', 65) . '.'));
    }

    public function testHelloWorld(): void
    {
        $samples = __DIR__ . '/../samples/programs/hello/hello_world.bf';
        $this->assertSame('Hello World!', $this->execute(self::readSample($samples)));
    }

    public function testPrecompiledInputPassthrough(): void
    {
        $this->assertSame('X', $this->execute(',.', 'X'));
    }

    public function testIncrementInput(): void
    {
        $this->assertSame('B', $this->execute(',+.', 'A'));
    }

    public function testReadSecondChar(): void
    {
        $this->assertSame('B', $this->execute(
            ',,.',
            "AB",
        ));
    }

    public function testClearCellWithMinusLoop(): void
    {
        $this->assertSame('', $this->execute('+++++[-]'));
    }

    public function testClearCellWithPlusLoop(): void
    {
        $this->assertSame('', $this->execute('+++++[+]'));
    }

    public function testMultiplyLoop(): void
    {
        // 5×10=50='2' — multiply-into-adjacent-cell then print (avoids a non-printing product)
        $this->assertSame('2', $this->execute('+++++[->++++++++++<]>.'));
    }

    public function testSeekRight(): void
    {
        // Current cell is 0 → `[` never enters; no `>` steps execute.
        $this->assertSame('', $this->execute('[>]'));
    }

    public function testSeekLeft(): void
    {
        $this->assertSame('', $this->execute('[<]'));
    }

    public function testLeadingLoopEliminated(): void
    {
        $this->assertSame('A', $this->execute('[---->+<]' . str_repeat('+', 65) . '.'));
    }

    public function testToPHPReturnsString(): void
    {
        $code = $this->compiler->toPHP('++++.');
        $this->assertNotEmpty($code);
    }

    public function testAddHeaderContainsTapeInit(): void
    {
        $header = $this->compiler->addHeader('', '');
        // Tape is a dynamic sparse array — no fixed-size pre-fill.
        $this->assertStringContainsString('$d=[]', $header);
        // Tape start is inlined as a compile-time constant, not a runtime intdiv call.
        $this->assertMatchesRegularExpression('/\$i=\d+;/', $header);
    }

    public function testAddHeaderWithInputEmbedsCodes(): void
    {
        $header = $this->compiler->addHeader('', 'A');
        // addHeader packs input with unpack('c*', $input . "\0") — signed bytes + trailing 0
        $this->assertStringContainsString('65', $header);
        $this->assertStringContainsString('0', $header);
    }

    public function testDebugOpcodeOutputsPointerAndCellValue(): void
    {
        $this->assertSame("65535: 0\n", $this->execute('#'));
    }

    public function testOutputIsNotBufferedPastDebugOpcode(): void
    {
        $this->assertSame("A65535: 65\n", $this->execute(str_repeat('+', 65) . '.#'));
    }

    public function testForkOpcodeIsIgnoredUnlessBrainforkEnabled(): void
    {
        $this->assertStringNotContainsString('pcntl_fork', $this->compiler->toPHP('Y'));
    }

    public function testForkOpcodeIsPreservedDuringCompilation(): void
    {
        $c = new Compiler(brainfork: true);
        $this->assertStringContainsString('pcntl_fork', $c->toPHP('Y'));
    }

    public function testForkFollowsBrainforkSpec(): void
    {
        // Per the Brainfork spec (https://esolangs.org/wiki/Brainfork):
        //   parent: current cell → 0, pointer unchanged
        //   child:  pointer moves +1, new cell → 1
        $c    = new Compiler(brainfork: true);
        $code = $c->toPHP('Y');
        // Parent sets current cell to 0 without moving the pointer.
        $this->assertStringContainsString('$d[$i]=0', $code);
        // Child increments pointer first, then sets new cell to 1.
        $this->assertStringContainsString('$d[++$i]=1', $code);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sampleProvider(): array
    {
        $helloDir = __DIR__ . '/../samples/programs/hello';
        $cristofaniDir = __DIR__ . '/../samples/collections/cristofani/programs';

        return [
            'hello_world' => [
                self::readSample("$helloDir/hello_world.bf"),
                '',
                'Hello World!',
            ],
            'rot13_A' => [
                self::readSample("$cristofaniDir/rot13.bf"),
                "A\n",
                "N\n",
            ],
        ];
    }

    #[DataProvider('sampleProvider')]
    public function testSamplePrograms(string $bf, string $input, string $expected): void
    {
        $this->assertSame($expected, $this->execute($bf, $input));
    }

    private function executeWith(int $cellBits, string $bf, string $input = ''): string
    {
        $compiler = new Compiler($cellBits);
        $level = ob_get_level();
        ob_start();

        try {
            eval($compiler->compile($bf, $input));
            $out = ob_get_clean();

            return is_string($out) ? $out : '';
        } finally {
            if (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    public function test8BitUnderflowWraps(): void
    {
        $this->assertSame(chr(255), $this->executeWith(8, '-.'));
    }

    public function test8BitOverflowWraps(): void
    {
        $this->assertSame(chr(0), $this->executeWith(8, str_repeat('+', 256) . '.'));
    }

    public function test8BitCounterInit(): void
    {
        // Common BF idiom: 0 − 157 ≡ 99 (mod 256) for “99 bottles” style counters
        $this->assertSame(chr(99), $this->executeWith(8, str_repeat('-', 157) . '.'));
    }

    public function test16BitUnderflowWraps(): void
    {
        // `#` prints the full cell value; chr() would only reflect mod 256
        $result = $this->executeWith(16, '-.#');
        $this->assertStringContainsString(': 65535', $result);
    }

    public function test16BitOverflowWraps(): void
    {
        $result = $this->executeWith(16, str_repeat('+', 65535) . '+#');
        $this->assertStringContainsString(': 0', $result);
    }

    public function testNoCellBitsUnboundedNoWrap(): void
    {
        // Compiler(0) is true-unbounded: no modular wrapping, raw PHP integers.
        // Decrementing below zero gives -1, not PHP_INT_MAX.
        $compiler = new Compiler(0);
        $code = $compiler->compile('-#');
        ob_start();
        eval($code);
        $out = ob_get_clean();
        $this->assertNotFalse($out);
        $this->assertStringContainsString(': -1', $out);
    }

    public function testInvalidCellBitsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Compiler(32);
    }

    public function test8BitHelloWorld(): void
    {
        $samples = __DIR__ . '/../samples/programs/hello/hello_world.bf';
        $this->assertSame('Hello World!', $this->executeWith(8, self::readSample($samples)));
    }

    /**
     * Class A — c at pos=0, before any other ops.
     * [[-]>+<]  ≡  if cell[0]: cell[1]++; cell[0]=0
     */
    public function testOneShotLoopClearFirst(): void
    {
        $this->assertSame('A', $this->executeWith(8, '+++++[[-]>+<]>' . str_repeat('+', 64) . '.'));
    }

    /**
     * Class A — c at pos=0, at the end of the body.
     * [>+<[-]]  ≡  if cell[0]: cell[1]++; cell[0]=0
     */
    public function testOneShotLoopClearLast(): void
    {
        $this->assertSame('A', $this->executeWith(8, '+++++[>+<[-]]>' . str_repeat('+', 64) . '.'));
    }

    /**
     * Class A — loop with c at pos=0 should not run when cell[0]=0.
     */
    public function testOneShotLoopSkippedWhenZero(): void
    {
        $this->assertSame('A', $this->executeWith(8, '>' . str_repeat('+', 5) . '<[[-]>+<]>' . str_repeat('+', 60) . '.'));
    }

    /**
     * Class A — c at pos=0 with a decrement on a non-source cell.
     * [[-]>-<]  ≡  if cell[0]: cell[1]--; cell[0]=0
     * Start with cell[1]=66='B', expect cell[1]=65='A' after one-shot.
     */
    public function testOneShotLoopDecrementsOnce(): void
    {
        $this->assertSame('A', $this->executeWith(8, '+++++>' . str_repeat('+', 66) . '<[[-]>-<]>.'));
    }

    /**
     * Class B — M at pos=0 decrement + c followed by P at same non-zero pos.
     * [<[-]+>-]  ≡  if cell[0]: cell[-1]=1; cell[0]=0
     * (regardless of how large cell[0] is, cell[-1] ends up 1, not cell[0])
     */
    public function testConstantSetLoopSetsOne(): void
    {
        $this->assertSame('A', $this->executeWith(8, '>+++++[<[-]+>-]<' . str_repeat('+', 64) . '.'));
    }

    /**
     * Class B — same pattern but destination to the right.
     * [>[-]+<-]  ≡  if cell[0]: cell[1]=1; cell[0]=0
     */
    public function testConstantSetLoopRightDirection(): void
    {
        $this->assertSame('A', $this->executeWith(8, '+++++[>[-]+<-]>' . str_repeat('+', 64) . '.'));
    }

    /**
     * Class B — constant-set must not run when source is zero.
     */
    public function testConstantSetLoopSkippedWhenZero(): void
    {
        $this->assertSame('A', $this->executeWith(8, '[<[-]+>-]>' . str_repeat('+', 65) . '.'));
    }

    /**
     * One-shot `[[-]>+<]` must fold to an `if`, not a `while($d[$i])` loop.
     */
    public function testOneShotLoopGeneratesIf(): void
    {
        // Leading `+` so the loop is not dead-stripped; body has `c` at pointer first.
        $code = $this->compiler->toPHP('+[[-]>+<]');
        $this->assertStringNotContainsString('while', $code);
        $this->assertStringContainsString('if(', $code);
    }

    public function testOneShotLoopClearLastGeneratesIf(): void
    {
        $code = $this->compiler->toPHP('+[>+<[-]]');
        $this->assertStringNotContainsString('while', $code);
        $this->assertStringContainsString('if(', $code);
    }

    /**
     * Constant-set `[<[-]+>-]` must fold to an `if`, not a `while`.
     */
    public function testConstantSetLoopGeneratesIf(): void
    {
        $code = $this->compiler->toPHP('+[<[-]+>-]');
        $this->assertStringNotContainsString('while', $code);
        $this->assertStringContainsString('if(', $code);
    }

    // -----------------------------------------------------------------------
    // Scan loops with step > 1: [>>], [<<<], [>>>>]
    // -----------------------------------------------------------------------

    /**
     * [>>] must scan right by 2 and stop on the first zero cell found.
     *
     * Tape after setup: cell[0]=1, cell[1]=1, cell[2]=0 (natural), pointer at cell[0].
     * [>>] → cell[0]=1 → +2 → cell[2]=0 → exit.
     * >> → cell[4]; +65 → cell[4]=65; . → 'A'.
     */
    public function testScanRightStepTwo(): void
    {
        // >+<  : cell[1]=1, back to cell[0]
        // +    : cell[0]=1
        // [>>] : cell[0]=1 → step to cell[2]=0, stop
        // >>   : move to cell[4]=0
        // +×65 : cell[4]=65
        // .    : print 'A'
        $this->assertSame('A', $this->execute('>+<+[>>]>>' . str_repeat('+', 65) . '.'));
    }

    /**
     * [<<] must scan left by 2 and stop on the first zero cell found.
     *
     * Tape: cell[0]=0, cell[2]=1, start at cell[4]=1.
     * [<<] → cell[4]=1 → −2 → cell[2]=1 → −2 → cell[0]=0 → exit.
     * +×65 → cell[0]=65; . → 'A'.
     */
    public function testScanLeftStepTwo(): void
    {
        // >>>>+  : move to cell[4], set cell[4]=1
        // <<+    : move to cell[2], set cell[2]=1
        // [<<]   : cell[2]=1 → step to cell[0]=0, stop
        // +×65   : cell[0]=65
        // .      : print 'A'
        $this->assertSame('A', $this->execute('>>>>+<<+[<<]' . str_repeat('+', 65) . '.'));
    }

    /**
     * [>>>>] must scan right by 4 steps per iteration (as in hanoi.bf line 162).
     *
     * Tape: cell[0]=1, cell[4]=1, cell[8]=0 (natural), pointer at cell[0].
     * [>>>>] → cell[0]=1 → cell[4]=1 → cell[8]=0 → exit.
     * >>>>  → cell[12]; +×65 → cell[12]=65; . → 'A'.
     */
    public function testScanRightStepFour(): void
    {
        // +        : cell[0]=1
        // >>>>+<<<< : cell[4]=1, back to cell[0]
        // [>>>>]   : scan to cell[8]=0
        // +×65     : cell[8]=65 (we land at cell[8], not cell[12])
        $this->assertSame('A', $this->execute('+>>>>+<<<<[>>>>]' . str_repeat('+', 65) . '.'));
    }

    /**
     * A scan loop with step > 1 must not execute when the current cell is zero.
     */
    public function testScanSkippedWhenZero(): void
    {
        // cell[0]=0 (default) → [>>] never enters → pointer stays at cell[0].
        // >+<  : cell[1]=65, back to cell[0]=0
        // [>>] : skipped (cell[0]=0)
        // >    : move to cell[1]=65
        // .    : print 'A'
        $this->assertSame('A', $this->execute('>' . str_repeat('+', 65) . '<[>>]>.'));
    }

    /**
     * [>>] must compile to a pointer-stepping loop, not a full arithmetic loop.
     * The exact loop form (while/for) is an implementation detail; what matters is
     * that the loop contains $i+=2 and no cell arithmetic.
     */
    public function testScanRightStepTwoGeneratesStepLoop(): void
    {
        $code = $this->compiler->toPHP('+[>>]');
        $this->assertStringContainsString('$i+=2', $code);
        $this->assertStringNotContainsString('$d[$i]+=', $code);
    }

    /**
     * [<<<] must compile to a pointer-stepping loop with $i-=3.
     */
    public function testScanLeftStepThreeGeneratesStepLoop(): void
    {
        $code = $this->compiler->toPHP('+[<<<]');
        $this->assertStringContainsString('$i-=3', $code);
        $this->assertStringNotContainsString('$d[$i]-=', $code);
    }

    // -----------------------------------------------------------------------
    // Dead loop elimination after cell-zeroing operations
    // -----------------------------------------------------------------------

    /**
     * [-][loop] — the second loop never fires (cell = 0 after [-]).
     * Verified by behaviour: running the dead loop would corrupt cell[1],
     * but cell[1] must remain untouched.
     */
    public function testDeadLoopAfterClear(): void
    {
        // ++ sets cell[0]=2, [-] clears it to 0, [->+<] is dead (cell[0]=0).
        // > moves to cell[1]=0, +65 sets it to 65, . prints 'A'.
        $this->assertSame('A', $this->execute('++[-][->+<]>' . str_repeat('+', 65) . '.'));
    }

    /**
     * [multiply][same-cell-loop] — second loop is dead because multiply zeroes cell[0].
     *
     * ++[->+<] → cell[1]+=2, cell[0]=0.
     * [->+<] again would add 0 to cell[1] and leave cell[0]=0 → no change.
     * Result must be the same as if the second loop weren't there.
     */
    public function testDeadLoopAfterMultiplyLoop(): void
    {
        // cell[0]=2, [->+<]: cell[1]+=2, cell[0]=0.
        // Second [->+<]: dead (cell[0]=0 at exit of first).
        // > +×63 . → cell[1]=65 → 'A'.
        $this->assertSame('A', $this->execute('++[->+<][->+<]>' . str_repeat('+', 63) . '.'));
    }

    /**
     * Dead loops must not execute: behaviour must be identical whether or not they are removed.
     */
    public function testDeadLoopDoesNotExecute(): void
    {
        // [<] scan: exits with current cell = 0. Following [->+<] is dead.
        // Pointer is at cell[0]=0 after [<]. Dead loop would incorrectly add to cell[-1].
        // Without the dead loop cell[0] stays 0 and +65. = 'A'.
        $this->assertSame('A', $this->execute('>+<[<][->+<]' . str_repeat('+', 65) . '.'));
    }

    // -----------------------------------------------------------------------
    // Constant-load optimisation: [-]+N → $d[$i]=N;
    // -----------------------------------------------------------------------

    /**
     * [-]+++++ must behave as a direct cell assignment (set cell to 5).
     */
    public function testConstantLoadBehavior(): void
    {
        // +++++ sets cell[0]=5, [-] clears to 0, +++++ sets back to 5.
        // Adding 60 more → 65 = 'A'.
        $this->assertSame('A', $this->execute(str_repeat('+', 5) . '[-]' . str_repeat('+', 65) . '.'));
    }

    /**
     * [-]+N must compile to a direct assignment, not a read-then-add sequence.
     */
    public function testConstantLoadGeneratesDirectAssign(): void
    {
        // `+` prefix prevents dead-loop elimination at position 0.
        $code = $this->compiler->toPHP('+[-]' . str_repeat('+', 5));
        // Should contain $d[$i]=5; not $d[$i]=($d[$i]+5)&...
        $this->assertStringContainsString('$d[$i]=5;', $code);
        $this->assertStringNotContainsString('$d[$i]+5', $code);
    }

    // -----------------------------------------------------------------------
    // Multiplication-pattern optimisation (nested copy loops)
    //
    // Canonical BF multiplication preserves the source operand B by copying
    // it through a temp T:
    //
    //   [->[->+>+<<]>>[-<<+>>]<<<]
    //   |  |          |          |
    //   |  inner1     inner2     restore pointer
    //   |  B → C,T    T → B
    //   A--   B=0     T=0
    //
    // After full loop: A = 0, B preserved, C += A * B, T = 0.
    // Pointer net delta inside the body must be 0.
    //
    // The optimiser should fold the entire outer loop into straight-line PHP:
    //   $d[$i+2] += $d[$i] * $d[$i+1];   // C += A * B
    //   $d[$i]    = 0;                   // A := 0
    // -----------------------------------------------------------------------

    private const string MUL_PATTERN = '[->[->+>+<<]>>[-<<+>>]<<<]';

    /**
     * Behaviour: 5 * 13 = 65 → 'A'.
     *
     * Tape layout: [A B C T]
     * Setup: cell[0]=5 (A), cell[1]=13 (B), cell[2]=0 (C), cell[3]=0 (T).
     * After the multiplier: A=0, B=13, C=65, T=0.
     * Move to C and print → 'A'.
     */
    public function testMultiplyBasic(): void
    {
        $bf = str_repeat('+', 5)             // A = 5
            . '>' . str_repeat('+', 13)      // B = 13
            . '<'                            // back to A
            . self::MUL_PATTERN              // C = A * B; A = 0
            . '>>.';                         // print C
        $this->assertSame('A', $this->execute($bf));
    }

    /**
     * Multiplication with operands swapped: 13 * 5 = 65 → 'A'.
     */
    public function testMultiplyLargeOperands(): void
    {
        $bf = str_repeat('+', 13)
            . '>' . str_repeat('+', 5)
            . '<'
            . self::MUL_PATTERN
            . '>>.';
        $this->assertSame('A', $this->execute($bf));
    }

    /**
     * Multiplication where the source is zero must do nothing.
     * cell[2] starts at 65; A=0 → loop never enters → cell[2] stays 65 → 'A'.
     */
    public function testMultiplySkippedWhenSourceZero(): void
    {
        $bf = '>' . str_repeat('+', 7)              // B = 7
            . '<'                                    // A = 0
            . self::MUL_PATTERN                      // dead — A is zero
            . '>>' . str_repeat('+', 65) . '.';      // cell[2] = 65 → 'A'
        $this->assertSame('A', $this->execute($bf));
    }

    /**
     * After multiplication B must be preserved. Run multiply, then reuse B
     * to overwrite C: 0 (A) → 13 (B preserved) → 13 (move B to C) → +52 = 65 → 'A'.
     */
    public function testMultiplyPreservesSource(): void
    {
        $bf = str_repeat('+', 5)
            . '>' . str_repeat('+', 13)
            . '<'
            . self::MUL_PATTERN          // A=0, B=13, C=65, T=0
            . '>'                        // at B
            . '[-]'                      // clear B (we only want to verify it WAS preserved)
            . '>'                        // at C
            . '[-]>[-]<<'                // wipe everything to start fresh; this isn't ideal, just check via behaviour below
            . '+'                        // not used
            . '';
        // Better: just directly verify B is non-zero after multiply.
        $bf = str_repeat('+', 5)
            . '>' . str_repeat('+', 13)
            . '<'
            . self::MUL_PATTERN          // A=0, B=13, C=65, T=0
            . '>>>>'                     // skip A,B,C,T; pointer at cell[4]=0
            . '+'                        // cell[4] = 1
            . '<<<'                      // pointer at B
            . '+++++++++++++++++++++++++++++++++++++++++++++++++++'  // 51 +
            . '.';                       // B = 13+51 = 64? No — we want 'A' (65)
        // 13 + 52 = 65
        $bf = str_repeat('+', 5)
            . '>' . str_repeat('+', 13)
            . '<'
            . self::MUL_PATTERN
            . '>'                        // pointer at B (preserved value 13)
            . str_repeat('+', 52)        // B = 13 + 52 = 65
            . '.';
        $this->assertSame('A', $this->execute($bf));
    }

    /**
     * Generated code: the outer multiply loop must NOT remain as a `while`.
     * Inner copy-loops are already flat-optimised; the optimiser must
     * collapse the outer iteration too.
     */
    public function testMultiplyDoesNotEmitOuterWhile(): void
    {
        $code = $this->compiler->toPHP('+' . self::MUL_PATTERN);
        $this->assertStringNotContainsString('while', $code);
    }

    /**
     * Generated code: the destination cell must be written using a
     * multiplication of two source cells (e.g. `$d[$i]*$d[$i+1]`).
     */
    public function testMultiplyEmitsMulExpression(): void
    {
        $code = $this->compiler->toPHP('+' . self::MUL_PATTERN);
        $this->assertMatchesRegularExpression(
            '/\$d\[\$i\+2\]\s*=.*\$d\[\$i\].*\*.*\$d\[\$i\+1\]/',
            $code,
        );
    }

    /**
     * Multiplication that is NOT canonical (e.g. unbalanced moves) must be
     * left as a regular while-loop and still produce correct results.
     */
    public function testNonCanonicalMultiplyStaysCorrect(): void
    {
        // [->>+<<] is not multiplication - it's a single copy. Result of
        // running it on cell[0]=5 is: cell[2] += 5, cell[0] = 0.
        // Adding 60 to cell[2] gives 65 → 'A'.
        $bf = str_repeat('+', 5) . '[->>+<<]>>' . str_repeat('+', 60) . '.';
        $this->assertSame('A', $this->execute($bf));
    }

    /**
     * Three-level nesting must not be misanalysed: it should fall back to a
     * while-loop. The optimiser should not crash or produce wrong code.
     *
     * Pattern: `[->[->[-]<]<]` — outer drains cell[0], each iter does nothing
     * (middle and inner loops are dead because cell[1] and cell[2] are zero).
     * After: cell[0] = 0; +65; . → 'A'.
     */
    public function testTripleNestedLoopFallsBack(): void
    {
        $bf = '+++++[->[->[-]<]<]' . str_repeat('+', 65) . '.';
        $this->assertSame('A', $this->execute($bf));
    }

    /**
     * The 16-bit mode must produce the same multiplication output. The
     * generated code differs (mask is 65535 instead of 255) but cell values
     * up to 256·256 fit cleanly. Verify a 13×5 = 65 multiplication.
     */
    public function testMultiply16Bit(): void
    {
        $bf = str_repeat('+', 13)
            . '>' . str_repeat('+', 5)
            . '<'
            . self::MUL_PATTERN
            . '>>.';
        $this->assertSame('A', $this->executeWith(16, $bf));
    }

    /**
     * After multiplication, T (the temp) must be zero — verify by reading
     * cell[3] via `#` debug print: it should print "3: 0".
     */
    public function testMultiplyTempIsZeroed(): void
    {
        $bf = str_repeat('+', 5)
            . '>' . str_repeat('+', 13)
            . '<'
            . self::MUL_PATTERN
            . '>>>#';
        $this->assertStringContainsString(': 0', $this->execute($bf));
    }

    // -----------------------------------------------------------------------
    // Conditional (divisor > 1) loop optimisation
    // -----------------------------------------------------------------------

    /**
     * Differential: optimised compiler output must match naive interpreter for
     * all conditional-optimisation patterns (divisor > 1, non-integer factors).
     * This is stronger than hand-computed expected values: any wrong shortcut
     * in genFastPath will produce a mismatch here.
     *
     * @return array<string, array{string}>
     */
    public static function conditionalOptProgramProvider(): array
    {
        return [
            // div=2, even source
            'div2 even'       => [str_repeat('+', 6)  . '[-->+<]>>'  . str_repeat('+', 62) . '.'],
            // div=2, small even source
            'div2 small'      => [str_repeat('+', 4)  . '[-->+<]>.'  ],
            // div=2, multiple non-integer effects
            'div2 multi'      => [str_repeat('+', 10) . '[-->>+<+<]>>'   . str_repeat('+', 60) . '.'],
            // div=3, source divisible by 3
            'div3 divisible'  => [str_repeat('+', 9)  . '[--->>++<<]>>'  . str_repeat('+', 59) . '.'],
            // div=3, source NOT divisible — while terminates via 8-bit wrap
            'div3 non-div'    => [str_repeat('+', 7)  . '[--->>+<<]>>.'  ],
            // div=4, source divisible
            'div4 divisible'  => [str_repeat('+', 12) . '[---->+<]>.'    ],
            // div=4, mixed integer and non-integer effects
            'div4 mixed'      => [str_repeat('+', 8)  . '[---->+>++<<]>>.' ],
            // zero source: loop body must not execute at all
            'zero source'     => ['>' . str_repeat('+', 65) . '<[-->+<]>.'],
        ];
    }

    #[DataProvider('conditionalOptProgramProvider')]
    public function testConditionalOptMatchesNaive(string $bf): void
    {
        $this->assertSame($this->naive($bf), $this->execute($bf));
    }

    /**
     * [-->+<]: controller decrements by 2 per iteration, cell[1] increments by
     * 1 per iteration.  Non-integer factor (1/2) → conditional optimisation.
     *
     * With even source (6): cell[1] += 3 = 3, then +62 → 65 → 'A'.
     */
    public function testConditionalOptEvenSource(): void
    {
        $bf = str_repeat('+', 6) . '[-->+<]>' . str_repeat('+', 62) . '.';
        $this->assertSame($this->naive($bf), $this->execute($bf));
    }

    /**
     * div=3, source divisible: 9/3=3 iters, cell[2]+=2*3=6.
     */
    public function testConditionalOptDiv3EvenSource(): void
    {
        $bf = str_repeat('+', 9) . '[--->>++<<]>>' . str_repeat('+', 59) . '.';
        $this->assertSame($this->naive($bf), $this->execute($bf));
    }

    /**
     * Multiple non-integer effects: div=2, effects {1:+1, 2:+1}.
     */
    public function testConditionalOptMultipleEffects(): void
    {
        $bf = str_repeat('+', 10) . '[-->>+<+<]>' . str_repeat('+', 60) . '.';
        $this->assertSame($this->naive($bf), $this->execute($bf));
    }

    /**
     * Code shape: a div-2 non-integer loop must emit a conditional `if` guard
     * for the while fallback — `if($d[$i]&1){while(…){…}}` — followed by the
     * unconditional fast path.
     */
    public function testConditionalOptEmitsIfGuard(): void
    {
        // [-->+<] has a non-integer factor (1/2)
        $code = $this->compiler->toPHP('+[-->+<]');
        $this->assertMatchesRegularExpression('/if\(\(\$d\[.*\]\?\?0\)&1\)/', $code);
    }

    /**
     * Code shape: the fallback while loop inside the guard must use the raw
     * `while` form (no further optimisation; the `W` pseudobytecode path).
     */
    public function testConditionalOptFallbackIsWhile(): void
    {
        $code = $this->compiler->toPHP('+[-->+<]');
        $this->assertStringContainsString('while($d[$i]??0){', $code);
    }

    /**
     * Code shape: the fast path must use a right-shift for power-of-2 divisors.
     * div=2 → `>>1`.
     */
    public function testConditionalOptUsesBitshift(): void
    {
        $code = $this->compiler->toPHP('+[-->+<]');
        $this->assertStringContainsString('>>1', $code);
    }

    /**
     * div=3 (non-power-of-2) → fast path must use `(int)($d[$i]/3)`.
     * `intdiv(a,b)` cannot be used here because the comma is the IR input
     * opcode and would be corrupted by the compilation pipeline.
     */
    public function testConditionalOptUsesIntdivForNonPow2(): void
    {
        // [--->>+<<]: div=3, effect at pos=2 is +1 (non-divisible by 3)
        $code = $this->compiler->toPHP('+[--->>+<<]');
        $this->assertStringContainsString('(int)(($d[$i]??0)/3)', $code);
    }

    /**
     * Critical correctness test: when divider is not a power of 2 AND the
     * start value is not divisible by it, in 8-bit mode gcd(D, 256) may equal
     * 1 (e.g. D=3), so the while loop terminates via wrap-around in some
     * non-obvious number of iterations.  Our generated code must:
     *   1. take the if-branch (guard true)
     *   2. run the while to completion, modifying cells per iteration
     *   3. afterwards $d[$i] = 0 → unconditional fast path becomes a no-op
     *   4. final cell state must equal what the original BF would produce
     *
     * For D=3, start=7 in 8-bit: 3·171 ≡ 1 (mod 256), so iterations = 7·171
     * mod 256 = 173.  Body `[--->>+<<]` adds 1 to cell[2] each iter, so
     * cell[2] = 173 mod 256 = 173 = 0xAD.
     */
    public function testConditionalOptWrapTerminationIsCorrect(): void
    {
        // cell[0]=7, [--->>+<<]: guard true (7%3≠0), while terminates via
        // 8-bit wrap-around after 173 iterations.  Compare against naive.
        $bf = str_repeat('+', 7) . '[--->>+<<]>>.';
        $this->assertSame($this->naive($bf), $this->execute($bf));
    }

    /**
     * Power-of-2 divisor D=4: fast path uses `$d[$i]>>2`.
     */
    public function testConditionalOptPow2Div4(): void
    {
        $bf = str_repeat('+', 12) . '[---->+<]>.';
        $this->assertSame($this->naive($bf), $this->execute($bf));
    }

    /**
     * Mixed integer and non-integer effects with D=4.
     */
    public function testConditionalOptMixedEffects(): void
    {
        $bf = str_repeat('+', 8) . '[---->+>++<<]>>.';
        $this->assertSame($this->naive($bf), $this->execute($bf));
    }

    /**
     * Zero source: neither path must produce side effects.
     */
    public function testConditionalOptZeroSource(): void
    {
        $bf = '>' . str_repeat('+', 65) . '<[-->+<]>.';
        $this->assertSame($this->naive($bf), $this->execute($bf));
    }
}
