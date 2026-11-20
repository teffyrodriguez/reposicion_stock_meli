<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Freshness;

/**
 * ML actualiza recomendaciones los lunes. Un registro está fresco si
 * FechaHoraActualizacion >= inicio de la ventana ML vigente.
 */
final class MlWeeklyWindow
{
    public function __construct(
        private readonly string $timezone = 'America/Argentina/Buenos_Aires',
        private readonly int $refreshHour = 0,
        private readonly int $refreshMinute = 0,
    ) {
    }

    public function start(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $tz = new \DateTimeZone($this->timezone);
        $now = ($now ?? new \DateTimeImmutable('now', $tz))->setTimezone($tz);

        $dow = (int) $now->format('N'); // 1=lunes
        $monday = $now->setTime(0, 0, 0)->modify('-' . ($dow - 1) . ' days');
        $refreshThisWeek = $monday->setTime($this->refreshHour, $this->refreshMinute, 0);

        if ($now < $refreshThisWeek) {
            return $monday->modify('-7 days')->setTime($this->refreshHour, $this->refreshMinute, 0);
        }

        return $refreshThisWeek;
    }
}
