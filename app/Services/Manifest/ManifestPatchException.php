<?php

namespace App\Services\Manifest;

use RuntimeException;

/**
 * A patch operation that could not be applied, said in enough detail to fix.
 *
 * The JSON-Patch library reports every failure as the string "An Operation
 * failed" and hides the real cause in the exception's `previous`. Applied to a
 * BATCH that is exactly no information: not which op, not which path segment,
 * not whether the index was out of range or a `test` simply disagreed.
 *
 * Observed live: a builder turn spent ~20 rejected propose_change calls hunting
 * a column index inside a tabs block, because every rejection came back with
 * the same seven words. The engine was fine the whole time — the address was
 * off by a few, and nothing said so.
 */
class ManifestPatchException extends RuntimeException
{
    public function __construct(
        public readonly int $opIndex,
        public readonly string $opType,
        public readonly string $opPath,
        public readonly string $reason,
        public readonly ?string $hint = null,
    ) {
        $label = $opType !== '' ? $opType : 'op';
        $where = $opPath !== '' ? " {$opPath}" : '';

        parent::__construct(
            "op #{$opIndex} ({$label}{$where}) failed: {$reason}".
            ($hint !== null ? " — {$hint}" : ''),
        );
    }

    /** The JSON pointer of the failing op, for a caller reporting per-op errors. */
    public function pointer(): string
    {
        return '/ops/'.$this->opIndex;
    }
}
