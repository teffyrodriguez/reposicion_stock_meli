<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Contracts;

use Contabilium\ReposicionFull\Domain\ReposicionJob;

interface QueueInterface
{
    /** @return bool true si se encoló; false si dedup evitó el duplicado */
    public function enqueue(ReposicionJob $job): bool;

    public function depth(): int;

    /** Claim atómico: saca de queue y deja en processing hasta $visibleAt. */
    public function claim(?int $visibleAtUnix = null): ?ReposicionJob;

    public function ack(ReposicionJob $job): void;

    public function scheduleRetry(ReposicionJob $job, int $visibleAtUnix): void;

    public function sendToDlq(ReposicionJob $job): void;

    /** Reencola mensajes de processing con score <= now. @return int recuperados */
    public function recoverStale(int $nowUnix): int;

    public function dlqDepth(): int;
}
