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

    public function testOutputSingleChar(): void
    {
        $this->assertSame('A', $this->execute(str_repeat('+', 65) . '.'));
    }

    public function testHelloWorld(): void
    {
        $samples = __DIR__ . '/../samples/prog/hello_world.b';
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
        $this->assertStringContainsString('array_fill', $header);
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

    public function testForkOpcodeIsPreservedDuringCompilation(): void
    {
        $this->assertStringContainsString('pcntl_fork', $this->compiler->toPHP('Y'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sampleProvider(): array
    {
        $dir = __DIR__ . '/../samples/prog';

        return [
            'hello_world' => [
                self::readSample("$dir/hello_world.b"),
                '',
                'Hello World!',
            ],
            'rot13_A' => [
                self::readSample("$dir/rot13.b"),
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

    public function testNoCellBitsWrapsAtPhpIntMax(): void
    {
        $compiler = new Compiler(0);
        $code = $compiler->compile('-#');
        ob_start();
        eval($code);
        $out = ob_get_clean();
        $this->assertNotFalse($out);
        $this->assertStringContainsString(': ' . PHP_INT_MAX, $out);
    }

    public function testInvalidCellBitsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Compiler(32);
    }

    public function test8BitHelloWorld(): void
    {
        $samples = __DIR__ . '/../samples/prog/hello_world.b';
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
}
