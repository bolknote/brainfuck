<?php

declare(strict_types=1);

final class Bf
{
    private string $code = '';

    private int $ptr = 0;

    public function program(): string
    {
        return $this->code."\n";
    }

    public function move(int $cell): void
    {
        $delta = $cell - $this->ptr;
        $this->code .= $delta > 0 ? str_repeat('>', $delta) : str_repeat('<', -$delta);
        $this->ptr = $cell;
    }

    public function raw(string $bf): void
    {
        $this->code .= $bf;
    }

    public function add(int $cell, int $amount = 1): void
    {
        $this->move($cell);
        $this->code .= str_repeat('+', $amount);
    }

    public function sub(int $cell, int $amount = 1): void
    {
        $this->move($cell);
        $this->code .= str_repeat('-', $amount);
    }

    public function clear(int $cell): void
    {
        $this->move($cell);
        $this->code .= '[-]';
    }

    public function set(int $cell, int $value): void
    {
        $this->clear($cell);
        if ($value > 0) {
            $this->add($cell, $value);
        }
    }

    public function read(int $cell): void
    {
        $this->move($cell);
        $this->code .= ',';
    }

    public function rand(int $cell): void
    {
        $this->move($cell);
        $this->code .= '@';
    }

    public function outCell(int $cell): void
    {
        $this->move($cell);
        $this->code .= '.';
    }

    public function outByte(int $value): void
    {
        $this->set(T::OUT, $value);
        $this->outCell(T::OUT);
    }

    public function outString(string $value): void
    {
        $value = str_replace("\n", "\r\n", $value);
        $len = strlen($value);
        if (0 === $len) {
            return;
        }

        $previous = ord($value[0]);
        $this->outByte($previous);

        for ($i = 0; $i < $len; ++$i) {
            if (0 === $i) {
                continue;
            }

            $current = ord($value[$i]);
            $this->move(T::OUT);
            $increase = ($current - $previous) & 0xFF;
            $decrease = ($previous - $current) & 0xFF;
            if ($increase <= $decrease) {
                $this->raw(str_repeat('+', $increase));
            } else {
                $this->raw(str_repeat('-', $decrease));
            }

            $this->outCell(T::OUT);
            $previous = $current;
        }
    }

    /**
     * @param list<int> $avoid
     */
    public function copy(int $src, int $dst, array $avoid = []): void
    {
        $tmp = $this->temp(array_merge([$src, $dst], $avoid));
        $this->clear($dst);
        $this->clear($tmp);
        $this->move($src);
        $this->raw('[');
        $this->sub($src);
        $this->add($dst);
        $this->add($tmp);
        $this->move($src);
        $this->raw(']');
        $this->move($tmp);
        $this->raw('[');
        $this->sub($tmp);
        $this->add($src);
        $this->move($tmp);
        $this->raw(']');
    }

    public function whileNonzero(int $cell, callable $body): void
    {
        $this->move($cell);
        $this->raw('[');
        $body();
        $this->move($cell);
        $this->raw(']');
    }

    public function onceIfNonzero(int $cell, callable $body): void
    {
        $flag = $this->temp([$cell]);
        $this->copy($cell, $flag);
        $this->move($flag);
        $this->raw('[');
        $this->clear($flag);
        $body();
        $this->move($flag);
        $this->raw(']');
    }

    public function onceIfZero(int $cell, callable $body): void
    {
        $flag = $this->temp([$cell]);
        $tmp = $this->temp([$cell, $flag]);
        $this->set($flag, 1);
        $this->copy($cell, $tmp, [$flag]);
        $this->move($tmp);
        $this->raw('[');
        $this->clear($tmp);
        $this->clear($flag);
        $this->move($tmp);
        $this->raw(']');
        $this->move($flag);
        $this->raw('[');
        $this->clear($flag);
        $body();
        $this->move($flag);
        $this->raw(']');
    }

    public function eqConst(int $cell, int $value, int $dst): void
    {
        $tmp = $this->temp([$cell, $dst]);
        $this->copy($cell, $tmp);
        $this->sub($tmp, $value);
        $this->set($dst, 1);
        $this->move($tmp);
        $this->raw('[');
        $this->clear($tmp);
        $this->clear($dst);
        $this->move($tmp);
        $this->raw(']');
    }

    public function onceIfEq(int $cell, int $value, callable $body): void
    {
        $flag = $this->temp();
        $this->eqConst($cell, $value, $flag);
        $this->move($flag);
        $this->raw('[');
        $this->clear($flag);
        $body();
        $this->move($flag);
        $this->raw(']');
    }

    public function outDigit(int $cell): void
    {
        $this->copy($cell, T::OUT);
        $this->add(T::OUT, 48);
        $this->outCell(T::OUT);
    }

    /**
     * @param list<int> $avoid
     */
    private function temp(array $avoid = []): int
    {
        for ($cell = 21; $cell < 41; ++$cell) {
            if (!in_array($cell, $avoid, true)) {
                return $cell;
            }
        }

        throw new RuntimeException('No scratch cell available');
    }
}

final class T
{
    public const int OUT = 0;

    public const int KEY = 1;

    public const int KEY2 = 2;

    public const int KEY3 = 3;

    public const int RUN = 4;

    public const int COMMAND = 5;

    public const int X = 6;

    public const int Y = 7;

    public const int COLOR = 8;

    public const int NEXT = 9;

    public const int RAND = 10;

    public const int WAIT = 11;

    public const int DELAY = 12;

    public const int FULL = 13;

    public const int RENDER = 14;

    public const int LINES = 15;

    public const int ROT_NEXT = 16;

    public const int BURN = 17;

    public const int PIECE = 18;

    public const int ROT = 19;

    public const int ROT_OLD = 20;

    public const int CAN_DOWN = 41;

    public const int CAN_LEFT = 42;

    public const int CAN_RIGHT = 43;

    public const int CAN_ROTATE = 44;

    public const int BOARD = 45;

    public const int WIDTH = 10;

    public const int HEIGHT = 20;
}

/**
 * @return array<int, list<array{0: int, 1: int}>>
 */
function pieceShapes(): array
{
    return [
        1 => [[0, 0], [1, 0], [0, 1], [1, 1]], // O
        2 => [[0, 0], [1, 0], [2, 0], [3, 0]], // I horizontal
        3 => [[0, 0], [0, 1], [0, 2], [0, 3]], // I vertical
        4 => [[0, 0], [1, 0], [2, 0], [1, 1]], // T down
        5 => [[1, 0], [0, 1], [1, 1], [1, 2]], // T left
        6 => [[1, 0], [0, 1], [1, 1], [2, 1]], // T up
        7 => [[0, 0], [0, 1], [1, 1], [0, 2]], // T right
        8 => [[0, 0], [0, 1], [1, 1], [2, 1]], // J down
        9 => [[0, 0], [1, 0], [0, 1], [0, 2]], // J left
        10 => [[0, 0], [1, 0], [2, 0], [2, 1]], // J up
        11 => [[1, 0], [1, 1], [0, 2], [1, 2]], // J right
        12 => [[2, 0], [0, 1], [1, 1], [2, 1]], // L down
        13 => [[0, 0], [0, 1], [0, 2], [1, 2]], // L left
        14 => [[0, 0], [1, 0], [2, 0], [0, 1]], // L up
        15 => [[0, 0], [1, 0], [1, 1], [1, 2]], // L right
        16 => [[1, 0], [2, 0], [0, 1], [1, 1]], // S horizontal
        17 => [[0, 0], [0, 1], [1, 1], [1, 2]], // S vertical
        18 => [[0, 0], [1, 0], [1, 1], [2, 1]], // Z horizontal
        19 => [[1, 0], [0, 1], [1, 1], [0, 2]], // Z vertical
    ];
}

/**
 * @param list<array{0: int, 1: int}> $shape
 *
 * @return array{0: int, 1: int}
 */
function shapeSize(array $shape): array
{
    $width = 0;
    $height = 0;
    foreach ($shape as [$x, $y]) {
        $width = max($width, $x + 1);
        $height = max($height, $y + 1);
    }

    return [$width, $height];
}

function board(int $x, int $y): int
{
    return T::BOARD + $y * T::WIDTH + $x;
}

function ifPosition(Bf $b, int $x, int $y, callable $body): void
{
    $b->onceIfEq(T::X, $x, static function () use ($b, $y, $body): void {
        $b->onceIfEq(T::Y, $y, $body);
    });
}

function ifCellFilled(Bf $b, int $cell, callable $body): void
{
    $b->onceIfNonzero($cell, $body);
}

/**
 * @param callable(list<array{0: int, 1: int}>, int, int): void $body
 */
function withActiveShape(Bf $b, callable $body): void
{
    foreach (pieceShapes() as $piece => $shape) {
        [$width, $height] = shapeSize($shape);
        $b->onceIfEq(T::PIECE, $piece, static function () use ($body, $shape, $width, $height): void {
            $body($shape, $width, $height);
        });
    }
}

function setBoardAtPiece(Bf $b, int $valueCell): void
{
    withActiveShape(
        $b,
        /**
         * @param list<array{0: int, 1: int}> $shape
         */
        static function (array $shape, int $width, int $height) use ($b, $valueCell): void {
            for ($y = 0; $y <= T::HEIGHT - $height; ++$y) {
                for ($x = 0; $x <= T::WIDTH - $width; ++$x) {
                    ifPosition($b, $x, $y, static function () use ($b, $shape, $x, $y, $valueCell): void {
                        foreach ($shape as [$dx, $dy]) {
                            $b->copy($valueCell, board($x + $dx, $y + $dy));
                        }
                    });
                }
            }
        }
    );
}

function clearBoardAtPiece(Bf $b): void
{
    withActiveShape(
        $b,
        /**
         * @param list<array{0: int, 1: int}> $shape
         */
        static function (array $shape, int $width, int $height) use ($b): void {
            for ($y = 0; $y <= T::HEIGHT - $height; ++$y) {
                for ($x = 0; $x <= T::WIDTH - $width; ++$x) {
                    ifPosition($b, $x, $y, static function () use ($b, $shape, $x, $y): void {
                        foreach ($shape as [$dx, $dy]) {
                            $b->clear(board($x + $dx, $y + $dy));
                        }
                    });
                }
            }
        }
    );
}

function checkPieceCells(Bf $b, int $can, int $moveX, int $moveY): void
{
    withActiveShape(
        $b,
        /**
         * @param list<array{0: int, 1: int}> $shape
         */
        static function (array $shape, int $width, int $height) use ($b, $moveX, $moveY, $can): void {
            for ($badX = T::WIDTH - $width + 1; $badX < T::WIDTH; ++$badX) {
                $b->onceIfEq(T::X, $badX, static function () use ($b, $can): void {
                    $b->clear($can);
                });
            }

            for ($badY = T::HEIGHT - $height + 1; $badY < T::HEIGHT; ++$badY) {
                $b->onceIfEq(T::Y, $badY, static function () use ($b, $can): void {
                    $b->clear($can);
                });
            }

            for ($y = 0; $y <= T::HEIGHT - $height; ++$y) {
                for ($x = 0; $x <= T::WIDTH - $width; ++$x) {
                    ifPosition($b, $x, $y, static function () use ($b, $shape, $x, $y, $moveX, $moveY, $can): void {
                        foreach ($shape as [$dx, $dy]) {
                            $targetX = $x + $dx + $moveX;
                            $targetY = $y + $dy + $moveY;
                            if ($targetX < 0 || $targetX >= T::WIDTH || $targetY < 0 || $targetY >= T::HEIGHT) {
                                $b->clear($can);
                                continue;
                            }

                            ifCellFilled($b, board($targetX, $targetY), static function () use ($b, $can): void {
                                $b->clear($can);
                            });
                        }
                    });
                }
            }
        }
    );
}

function randomizeNext(Bf $b): void
{
    $b->set(T::NEXT, 1);
    $b->rand(T::RAND);
    $b->whileNonzero(T::RAND, static function () use ($b): void {
        $b->sub(T::RAND);
        $b->add(T::NEXT);
        $b->onceIfEq(T::NEXT, 8, static function () use ($b): void {
            $b->set(T::NEXT, 1);
        });
    });
}

function accelerate(Bf $b): void
{
    // Fixed-speed mode: keep the handcrafted BF delay stable while tuning gameplay.
}

function clearLines(Bf $b): void
{
    for ($row = T::HEIGHT - 1; $row >= 0; --$row) {
        $b->set(T::FULL, 1);
        for ($x = 0; $x < T::WIDTH; ++$x) {
            $b->onceIfZero(board($x, $row), static function () use ($b): void {
                $b->clear(T::FULL);
            });
        }

        $b->onceIfNonzero(T::FULL, static function () use ($b, $row): void {
            $b->add(T::LINES);
            $b->onceIfEq(T::LINES, 10, static function () use ($b): void {
                $b->clear(T::LINES);
            });
            for ($y = $row; $y > 0; --$y) {
                for ($x = 0; $x < T::WIDTH; ++$x) {
                    $b->copy(board($x, $y - 1), board($x, $y));
                }
            }

            for ($x = 0; $x < T::WIDTH; ++$x) {
                $b->clear(board($x, 0));
            }

            accelerate($b);
        });
    }
}

function spawnPiece(Bf $b): void
{
    $b->onceIfEq(T::NEXT, 1, static function () use ($b): void {
        $b->set(T::PIECE, 1);
        $b->set(T::COLOR, 1);
    });
    $b->onceIfEq(T::NEXT, 2, static function () use ($b): void {
        $b->set(T::PIECE, 2);
        $b->set(T::COLOR, 2);
    });
    $b->onceIfEq(T::NEXT, 3, static function () use ($b): void {
        $b->set(T::PIECE, 4);
        $b->set(T::COLOR, 5);
    });
    $b->onceIfEq(T::NEXT, 4, static function () use ($b): void {
        $b->set(T::PIECE, 8);
        $b->set(T::COLOR, 4);
    });
    $b->onceIfEq(T::NEXT, 5, static function () use ($b): void {
        $b->set(T::PIECE, 12);
        $b->set(T::COLOR, 6);
    });
    $b->onceIfEq(T::NEXT, 6, static function () use ($b): void {
        $b->set(T::PIECE, 16);
        $b->set(T::COLOR, 3);
    });
    $b->onceIfEq(T::NEXT, 7, static function () use ($b): void {
        $b->set(T::PIECE, 18);
        $b->set(T::COLOR, 7);
    });
    $b->onceIfEq(T::COLOR, 8, static function () use ($b): void {
        $b->set(T::COLOR, 5);
    });
    randomizeNext($b);
    $b->set(T::X, 3);
    $b->clear(T::Y);
    $b->clear(T::ROT);
    foreach (pieceShapes() as $piece => $shape) {
        $b->onceIfEq(T::PIECE, $piece, static function () use ($b, $shape): void {
            foreach ($shape as [$dx, $dy]) {
                $b->onceIfNonzero(board(3 + $dx, $dy), static function () use ($b): void {
                    $b->clear(T::RUN);
                });
            }
        });
    }
}

function lockPiece(Bf $b): void
{
    setBoardAtPiece($b, T::COLOR);
    clearLines($b);
    accelerate($b);
    spawnPiece($b);
}

function canMoveDown(Bf $b, int $can): void
{
    $b->set($can, 1);
    checkPieceCells($b, $can, 0, 1);
}

function tryDown(Bf $b): void
{
    $can = T::CAN_DOWN;
    canMoveDown($b, $can);
    $b->onceIfNonzero($can, static function () use ($b): void {
        $b->add(T::Y);
    });
    $b->onceIfZero($can, static function () use ($b): void {
        lockPiece($b);
    });
}

function tryLeft(Bf $b): void
{
    $can = T::CAN_LEFT;
    $b->set($can, 1);
    checkPieceCells($b, $can, -1, 0);
    $b->onceIfNonzero($can, static function () use ($b): void {
        $b->sub(T::X);
    });
}

function tryRight(Bf $b): void
{
    $can = T::CAN_RIGHT;
    $b->set($can, 1);
    checkPieceCells($b, $can, 1, 0);
    $b->onceIfNonzero($can, static function () use ($b): void {
        $b->add(T::X);
    });
}

function tryRotate(Bf $b): void
{
    $can = T::CAN_ROTATE;
    $b->copy(T::PIECE, T::ROT_OLD);
    $b->onceIfEq(T::ROT_OLD, 2, static function () use ($b): void {
        $b->set(T::PIECE, 3);
    });
    $b->onceIfEq(T::ROT_OLD, 3, static function () use ($b): void {
        $b->set(T::PIECE, 2);
    });
    $b->onceIfEq(T::ROT_OLD, 4, static function () use ($b): void {
        $b->set(T::PIECE, 5);
    });
    $b->onceIfEq(T::ROT_OLD, 5, static function () use ($b): void {
        $b->set(T::PIECE, 6);
    });
    $b->onceIfEq(T::ROT_OLD, 6, static function () use ($b): void {
        $b->set(T::PIECE, 7);
    });
    $b->onceIfEq(T::ROT_OLD, 7, static function () use ($b): void {
        $b->set(T::PIECE, 4);
    });
    $b->onceIfEq(T::ROT_OLD, 8, static function () use ($b): void {
        $b->set(T::PIECE, 9);
    });
    $b->onceIfEq(T::ROT_OLD, 9, static function () use ($b): void {
        $b->set(T::PIECE, 10);
    });
    $b->onceIfEq(T::ROT_OLD, 10, static function () use ($b): void {
        $b->set(T::PIECE, 11);
    });
    $b->onceIfEq(T::ROT_OLD, 11, static function () use ($b): void {
        $b->set(T::PIECE, 8);
    });
    $b->onceIfEq(T::ROT_OLD, 12, static function () use ($b): void {
        $b->set(T::PIECE, 13);
    });
    $b->onceIfEq(T::ROT_OLD, 13, static function () use ($b): void {
        $b->set(T::PIECE, 14);
    });
    $b->onceIfEq(T::ROT_OLD, 14, static function () use ($b): void {
        $b->set(T::PIECE, 15);
    });
    $b->onceIfEq(T::ROT_OLD, 15, static function () use ($b): void {
        $b->set(T::PIECE, 12);
    });
    $b->onceIfEq(T::ROT_OLD, 16, static function () use ($b): void {
        $b->set(T::PIECE, 17);
    });
    $b->onceIfEq(T::ROT_OLD, 17, static function () use ($b): void {
        $b->set(T::PIECE, 16);
    });
    $b->onceIfEq(T::ROT_OLD, 18, static function () use ($b): void {
        $b->set(T::PIECE, 19);
    });
    $b->onceIfEq(T::ROT_OLD, 19, static function () use ($b): void {
        $b->set(T::PIECE, 18);
    });
    $b->set($can, 1);
    checkPieceCells($b, $can, 0, 0);
    $b->onceIfZero($can, static function () use ($b): void {
        $b->copy(T::ROT_OLD, T::PIECE);
    });
}

function applyCommand(Bf $b): void
{
    $b->onceIfEq(T::COMMAND, 1, static function () use ($b): void {
        tryLeft($b);
    });
    $b->onceIfEq(T::COMMAND, 2, static function () use ($b): void {
        tryRight($b);
    });
    $b->onceIfEq(T::COMMAND, 3, static function () use ($b): void {
        tryDown($b);
    });
    $b->onceIfEq(T::COMMAND, 4, static function () use ($b): void {
        tryRotate($b);
    });
    $b->clear(T::COMMAND);
}

function delayPoll(Bf $b): void
{
    $b->set(T::BURN, 255);
    $b->whileNonzero(T::BURN, static function () use ($b): void {
        pollInput($b);
        applyCommand($b);
        $b->sub(T::BURN);
    });
}

function pollInput(Bf $b): void
{
    $b->read(T::KEY);
    $b->onceIfEq(T::KEY, ord('q'), static function () use ($b): void {
        $b->clear(T::RUN);
        $b->clear(T::WAIT);
        $b->clear(T::BURN);
    });
    $b->onceIfEq(T::KEY, 3, static function () use ($b): void {
        $b->clear(T::RUN);
        $b->clear(T::WAIT);
        $b->clear(T::BURN);
    });
    $b->onceIfEq(T::KEY, ord('a'), static function () use ($b): void {
        $b->set(T::COMMAND, 1);
    });
    $b->onceIfEq(T::KEY, ord('d'), static function () use ($b): void {
        $b->set(T::COMMAND, 2);
    });
    $b->onceIfEq(T::KEY, ord('s'), static function () use ($b): void {
        $b->set(T::COMMAND, 3);
    });
    $b->onceIfEq(T::KEY, ord('w'), static function () use ($b): void {
        $b->set(T::COMMAND, 4);
    });
    $b->onceIfEq(T::KEY, 27, static function () use ($b): void {
        $b->read(T::KEY2);
        $b->read(T::KEY3);
        $b->onceIfEq(T::KEY2, 91, static function () use ($b): void {
            $b->onceIfEq(T::KEY3, 68, static function () use ($b): void {
                $b->set(T::COMMAND, 1);
            });
            $b->onceIfEq(T::KEY3, 67, static function () use ($b): void {
                $b->set(T::COMMAND, 2);
            });
            $b->onceIfEq(T::KEY3, 66, static function () use ($b): void {
                $b->set(T::COMMAND, 3);
            });
            $b->onceIfEq(T::KEY3, 65, static function () use ($b): void {
                $b->set(T::COMMAND, 4);
            });
        });
    });
}

function renderCell(Bf $b): void
{
    $b->onceIfZero(T::RENDER, static function () use ($b): void {
        $b->outString('  ');
    });
    $b->onceIfNonzero(T::RENDER, static function () use ($b): void {
        $b->outString("\033[30;4");
        $b->outDigit(T::RENDER);
        $b->outString("m⠿⠿\033[0m");
    });
}

function render(Bf $b): void
{
    setBoardAtPiece($b, T::COLOR);
    $b->outString("\033[H");
    $b->outString("╔════════════════════╗   BF TETRIS\n");
    for ($y = 0; $y < T::HEIGHT; ++$y) {
        $b->outString('║');
        for ($x = 0; $x < T::WIDTH; ++$x) {
            $b->copy(board($x, $y), T::RENDER);
            renderCell($b);
        }

        $b->outString('║');
        if (0 === $y) {
            $b->outString('   ←/→ move  ↑ rotate');
        } elseif (1 === $y) {
            $b->outString('   ↓ drop    q quit');
        } elseif (2 === $y) {
            $b->outString('   next ');
            $b->copy(T::NEXT, T::RENDER);
            $b->onceIfEq(T::NEXT, 3, static function () use ($b): void {
                $b->set(T::RENDER, 5);
            });
            $b->onceIfEq(T::NEXT, 4, static function () use ($b): void {
                $b->set(T::RENDER, 4);
            });
            $b->onceIfEq(T::NEXT, 5, static function () use ($b): void {
                $b->set(T::RENDER, 6);
            });
            $b->onceIfEq(T::NEXT, 6, static function () use ($b): void {
                $b->set(T::RENDER, 3);
            });
            $b->onceIfEq(T::NEXT, 7, static function () use ($b): void {
                $b->set(T::RENDER, 7);
            });
            renderCell($b);
            $b->outString('   lines ');
            $b->outDigit(T::LINES);
        }

        $b->outString("\n");
    }

    $b->outString("╚════════════════════╝\n");
    clearBoardAtPiece($b);
}

function renderGameOver(Bf $b): void
{
    $b->outString("\033[9;1H║     \033[1;35mGAME OVER\033[0m      ║");
    $b->outString("\033[24;1H\033[?25h\n");
}

$b = new Bf();
$b->outString("\033[?25l\033[2J\033[H");
$b->set(T::RUN, 1);
$b->set(T::DELAY, 180);
randomizeNext($b);
spawnPiece($b);
$b->whileNonzero(T::RUN, static function () use ($b): void {
    render($b);
    $b->copy(T::DELAY, T::WAIT);
    $b->whileNonzero(T::WAIT, static function () use ($b): void {
        delayPoll($b);
        $b->sub(T::WAIT);
    });
    tryDown($b);
});
renderGameOver($b);

function findZopfliBinary(): ?string
{
    $out = [];
    $code = 0;
    exec('command -v zopfli 2>/dev/null', $out, $code);
    if (0 !== $code || [] === $out) {
        return null;
    }

    $path = trim($out[0]);

    return '' !== $path ? $path : null;
}

/**
 * @return array{0: string, 1: bool} [gzip bytes, used zopfli]
 */
function gzipProgram(string $program, bool $useZopfli): array
{
    if ($useZopfli) {
        $zopfli = findZopfliBinary();
        if (null === $zopfli) {
            fwrite(\STDERR, "zopfli not found in PATH; using zlib gzip\n");
        }
    } else {
        $zopfli = null;
    }

    if (null !== $zopfli) {
        $tmp = tempnam(sys_get_temp_dir(), 'tetbf');
        if (false === $tmp) {
            throw new RuntimeException('tempnam failed');
        }

        try {
            if (false === file_put_contents($tmp, $program)) {
                throw new RuntimeException('Failed to write temp file for zopfli');
            }

            $iter = getenv('ZOPFLI_ITERATIONS');
            $iterFlag = '';
            if (
                is_string($iter)
                && '' !== $iter
                && ctype_digit($iter)
                && (int) $iter >= 1
            ) {
                $iterFlag = ' --i'.(int) $iter;
            }

            $cmd = escapeshellarg($zopfli).$iterFlag.' --gzip -c '.escapeshellarg($tmp).' 2>/dev/null';
            $payload = shell_exec($cmd);
            if (is_string($payload) && str_starts_with($payload, "\x1f\x8b")) {
                return [$payload, true];
            }
        } finally {
            @unlink($tmp);
        }
    }

    $payload = gzencode($program, 9);
    if (false === $payload) {
        throw new RuntimeException('Failed to gzip generated Tetris program');
    }

    return [$payload, false];
}

$target = __DIR__.'/../samples/programs/demos/tetris.bf';
$cliArgv = [];
$rawArgv = $_SERVER['argv'] ?? null;
if (is_array($rawArgv)) {
    foreach ($rawArgv as $v) {
        if (is_string($v)) {
            $cliArgv[] = $v;
        }
    }
}

$useZopfli = in_array('--zopfli', $cliArgv, true) || in_array('-Z', $cliArgv, true);
[$payload, $usedZopfli] = gzipProgram($b->program(), $useZopfli);
file_put_contents($target, "#!/usr/bin/env -S ./bfrun -@ -I -z\n".$payload);
chmod($target, 0o755);
$via = $usedZopfli ? 'zopfli' : 'zlib';
fwrite(\STDERR, "Generated {$target} (gzip via {$via})\n");
