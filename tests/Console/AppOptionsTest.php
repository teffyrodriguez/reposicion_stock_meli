<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Console;

use Contabilium\ReposicionFull\Console\App;
use PHPUnit\Framework\TestCase;

final class AppOptionsTest extends TestCase
{
    public function testOptionIntUsesFallbackWhenMissing(): void
    {
        self::assertSame(30, App::optionInt([], 'sleep', 30, 0));
    }

    public function testOptionIntAcceptsZeroWhenMinimumIsZero(): void
    {
        self::assertSame(0, App::optionInt(['sleep' => '0'], 'sleep', 30, 0));
    }

    public function testOptionIntRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::optionInt(['batch-size' => '-1'], 'batch-size', 100);
    }
}
