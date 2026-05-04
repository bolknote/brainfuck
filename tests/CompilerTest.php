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
}
