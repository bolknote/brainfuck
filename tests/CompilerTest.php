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

    // --- compile() + eval() helpers ---

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

    // --- Output ---

    public function testOutputSingleChar(): void
    {
        $this->assertSame('A', $this->execute(str_repeat('+', 65) . '.'));
    }

    public function testHelloWorld(): void
    {
        $samples = __DIR__ . '/../samples/prog/hello_world.b';
        $this->assertSame('Hello World!', $this->execute(self::readSample($samples)));
    }

    // --- Input ---

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

    // --- Optimisations: cell arithmetic ---

    public function testClearCellWithMinusLoop(): void
    {
        // [-] must zero the cell regardless of starting value
        $this->assertSame('', $this->execute('+++++[-]'));
    }

    public function testClearCellWithPlusLoop(): void
    {
        // [+] wraps and should also zero the cell (by optimiser)
        $this->assertSame('', $this->execute('+++++[+]'));
    }

    public function testMultiplyLoop(): void
    {
        // [->+++<] multiplies cell 0 by 3 into cell 1, then print cell 1
        // Starting value 4 → 4*3 = 12 (no printable char, check via workaround)
        // Instead: set cell 0 to 1, cell 1 gets 3 → chr(3)... hard to read, use a
        // known result: 5 * 10 = 50 = '2'
        $this->assertSame('2', $this->execute('+++++[->++++++++++<]>.'));
    }

    // --- Optimisations: pointer moves ---

    public function testSeekRight(): void
    {
        // [>] should scan right to first zero cell; since tape starts at 0, stays put
        $this->assertSame('', $this->execute('[>]'));
    }

    public function testSeekLeft(): void
    {
        $this->assertSame('', $this->execute('[<]'));
    }

    // --- Dead-code elimination ---

    public function testLeadingLoopEliminated(): void
    {
        // A loop at position 0 is dead (cell is 0), program just outputs 'A'
        $this->assertSame('A', $this->execute('[---->+<]' . str_repeat('+', 65) . '.'));
    }

    // --- toPHP ---

    public function testToPHPReturnsString(): void
    {
        $code = $this->compiler->toPHP('++++.');
        $this->assertNotEmpty($code);
    }

    public function testAddHeaderContainsTapeInit(): void
    {
        $header = $this->compiler->addHeader('', '');
        $this->assertStringContainsString('array_fill', $header);
        $this->assertStringContainsString('intdiv', $header);
    }

    public function testAddHeaderWithInputEmbedsCodes(): void
    {
        $header = $this->compiler->addHeader('', 'A');
        // 'A' = 65, plus null terminator 0
        $this->assertStringContainsString('65', $header);
        $this->assertStringContainsString('0', $header);
    }

    // --- Extension opcodes ---

    public function testDebugOpcodeOutputsPointerAndCellValue(): void
    {
        $this->assertSame("65535: 0\n", $this->execute('#'));
    }

    public function testForkOpcodeIsPreservedDuringCompilation(): void
    {
        $this->assertStringContainsString('pcntl_fork', $this->compiler->toPHP('Y'));
    }

    // --- Sample programs ---

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

    // --- Cell wrapping ---

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
        // Decrement cell from 0 → should give 255 (8-bit wrap)
        $this->assertSame(chr(255), $this->executeWith(8, '-.'));
    }

    public function test8BitOverflowWraps(): void
    {
        // Increment cell from 255 → should give 0 (8-bit wrap)
        $this->assertSame(chr(0), $this->executeWith(8, str_repeat('+', 256) . '.'));
    }

    public function test8BitCounterInit(): void
    {
        // 0 - 157 = 99 in 8-bit arithmetic (classic beer counter init)
        $this->assertSame(chr(99), $this->executeWith(8, str_repeat('-', 157) . '.'));
    }

    public function test16BitUnderflowWraps(): void
    {
        // Decrement cell from 0 → should give 65535 (16-bit wrap)
        // chr() takes code mod 256, so chr(65535) = chr(255)
        $result = $this->executeWith(16, '-.#');
        $this->assertStringContainsString(': 65535', $result);
    }

    public function test16BitOverflowWraps(): void
    {
        // Set cell to 65535, increment → should give 0 (16-bit wrap)
        $result = $this->executeWith(16, str_repeat('+', 65535) . '+#');
        $this->assertStringContainsString(': 0', $result);
    }

    public function testNoCellBitsDisablesWrapping(): void
    {
        // Without wrapping, 0 - 1 = -1 (PHP integer)
        $compiler = new Compiler(0);
        $code = $compiler->compile('-#');
        $level = ob_get_level();
        ob_start();
        eval($code);
        $out = ob_get_clean() ?? '';
        $this->assertStringContainsString(': -1', $out);
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

    // --- c-loop optimisation (one-shot and constant-set) ---
    //
    // Discriminating value: cell[0] = 5.
    // Wrong optimiser: multiplies destination by source → result 5.
    // Correct opt:     destination is set to a constant (1) → result 1.
    // We verify by moving the destination to a printable ASCII range:
    //   correct  →  1 + 64 = 65 → 'A'
    //   wrong    →  5 + 64 = 69 → 'E'

    /**
     * Class A — c at pos=0, before any other ops.
     * [[-]>+<]  ≡  if cell[0]: cell[1]++; cell[0]=0
     */
    public function testOneShotLoopClearFirst(): void
    {
        // +++++  → cell[0]=5
        // [[-]>+<]  → one-shot: cell[1]=1, cell[0]=0
        // >++++...+  → cell[1]+64 = 65
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
        // cell[0]=0, cell[1]=5; loop must not run; print cell[1] as chr(5+60)='A'
        $this->assertSame('A', $this->executeWith(8, '>' . str_repeat('+', 5) . '<[[-]>+<]>' . str_repeat('+', 60) . '.'));
    }

    /**
     * Class A — c at pos=0 with a decrement on a non-source cell.
     * [[-]>-<]  ≡  if cell[0]: cell[1]--; cell[0]=0
     * Start with cell[1]=66='B', expect cell[1]=65='A' after one-shot.
     */
    public function testOneShotLoopDecrementsOnce(): void
    {
        // cell[0]=5, cell[1]=66
        // one-shot: cell[1] -= 1 → 65, cell[0]=0
        $this->assertSame('A', $this->executeWith(8, '+++++>' . str_repeat('+', 66) . '<[[-]>-<]>.'));
    }

    /**
     * Class B — M at pos=0 decrement + c followed by P at same non-zero pos.
     * [<[-]+>-]  ≡  if cell[0]: cell[-1]=1; cell[0]=0
     * (regardless of how large cell[0] is, cell[-1] ends up 1, not cell[0])
     */
    public function testConstantSetLoopSetsOne(): void
    {
        // >+++++  → cell[1]=5 (this is the "source")
        // [<[-]+>-]  → constant-set: cell[0]=1, cell[1]=0
        // <++++...+  → cell[0]+64 = 65
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
        // cell[0]=0; loop must not run; cell[1] stays 0; 0+65=65='A'
        $this->assertSame('A', $this->executeWith(8, '[<[-]+>-]>' . str_repeat('+', 65) . '.'));
    }

    // --- Generated-code shape tests (RED until the optimiser is implemented) ---

    /**
     * One-shot loops must compile to an `if` statement, not a `while` loop.
     * With the current bail-out, toPHP emits `while($d[$i])` for these bodies.
     */
    public function testOneShotLoopGeneratesIf(): void
    {
        // '+' ensures the loop is not dead-loop-eliminated (cell[0] may be non-zero).
        // [[-]>+<] — c at pos=0 first
        $code = $this->compiler->toPHP('+[[-]>+<]');
        $this->assertStringNotContainsString('while', $code);
        $this->assertStringContainsString('if(', $code);
    }

    public function testOneShotLoopClearLastGeneratesIf(): void
    {
        // [>+<[-]] — c at pos=0 last
        $code = $this->compiler->toPHP('+[>+<[-]]');
        $this->assertStringNotContainsString('while', $code);
        $this->assertStringContainsString('if(', $code);
    }

    /**
     * Constant-set loops must compile to an `if` statement.
     */
    public function testConstantSetLoopGeneratesIf(): void
    {
        // [<[-]+>-] — M at pos=0, c+P at pos=-1
        $code = $this->compiler->toPHP('+[<[-]+>-]');
        $this->assertStringNotContainsString('while', $code);
        $this->assertStringContainsString('if(', $code);
    }
}
