<?php

namespace App\Enums;

/**
 * Whether a connector action reads from or writes to an external system.
 *
 * Reads run freely; writes to a system of record happen only behind an
 * approval gate (or on an action explicitly marked `safe`) — the
 * propose-don't-mutate invariant.
 */
enum ConnectorEffect: string
{
    case Read = 'read';
    case Write = 'write';

    public function isWrite(): bool
    {
        return $this === self::Write;
    }
}
