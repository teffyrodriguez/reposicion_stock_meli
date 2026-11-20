<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Console;

use Contabilium\ReposicionFull\Console\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    public function testLoadFileSkipsCommentsAndStripsQuotes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($path);
        file_put_contents($path, "\xEF\xBB\xBF# comment\nexport DB_DSN=\"sqlsrv:Server=localhost\"\nDB_USER='sa'\n\n");

        try {
            $values = Env::loadFile($path);
        } finally {
            unlink($path);
        }

        self::assertSame('sqlsrv:Server=localhost', $values['DB_DSN']);
        self::assertSame('sa', $values['DB_USER']);
    }

    public function testPickPrefersProcessValue(): void
    {
        self::assertSame('from-process', Env::pick('from-process', ['DB_DSN' => 'from-file'], 'DB_DSN'));
        self::assertSame('from-file', Env::pick(false, ['DB_DSN' => 'from-file'], 'DB_DSN'));
        self::assertSame('default', Env::pick(false, [], 'DB_DSN', 'default'));
    }
}
