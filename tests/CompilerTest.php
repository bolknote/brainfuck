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

    public function testNoCellBitsDisablesWrapping(): void
    {
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
}
