<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Tests\Freshness;

use Contabilium\ReposicionFull\Freshness\MlWeeklyWindow;
use PHPUnit\Framework\TestCase;

final class MlWeeklyWindowTest extends TestCase
{
    public function testWednesdayUsesCurrentMonday(): void
    {
        $window = new MlWeeklyWindow('America/Argentina/Buenos_Aires', 0, 0);
        $wed = new \DateTimeImmutable('2026-10-28 15:00:00', new \DateTimeZone('America/Argentina/Buenos_Aires'));

        $start = $window->start($wed);

        self::assertSame('2026-10-26', $start->format('Y-m-d')); // lunes
        self::assertSame('00:00:00', $start->format('H:i:s'));
    }

    public function testMondayBeforeRefreshUsesPreviousMonday(): void
    {
        $window = new MlWeeklyWindow('America/Argentina/Buenos_Aires', 12, 0);
        $mondayMorning = new \DateTimeImmutable('2026-10-26 09:00:00', new \DateTimeZone('America/Argentina/Buenos_Aires'));

        $start = $window->start($mondayMorning);

        self::assertSame('2026-10-19', $start->format('Y-m-d'));
        self::assertSame('12:00:00', $start->format('H:i:s'));
    }

    public function testMondayAfterRefreshUsesThisMonday(): void
    {
        $window = new MlWeeklyWindow('America/Argentina/Buenos_Aires', 12, 0);
        $mondayAfternoon = new \DateTimeImmutable('2026-10-26 14:00:00', new \DateTimeZone('America/Argentina/Buenos_Aires'));

        $start = $window->start($mondayAfternoon);

        self::assertSame('2026-10-26', $start->format('Y-m-d'));
        self::assertSame('12:00:00', $start->format('H:i:s'));
    }
}
