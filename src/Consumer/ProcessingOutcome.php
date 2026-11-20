<?php

declare(strict_types=1);

namespace Contabilium\ReposicionFull\Consumer;

enum ProcessingOutcome: string
{
    case NoopFresh = 'noop_fresh';
    case DiscardedInactive = 'discarded_inactive';
    case UpsertOk = 'upsert_ok';
    case UpsertEmpty = 'upsert_empty';
    case RetryScheduled = 'retry_scheduled';
    case SentToDlq = 'sent_to_dlq';
}
