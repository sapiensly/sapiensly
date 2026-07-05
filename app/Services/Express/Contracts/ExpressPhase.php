<?php

namespace App\Services\Express\Contracts;

use App\Models\PipelineRun;
use App\Services\Express\ExpressContext;

/**
 * One deterministic step of the Express pipeline. A phase may call gates
 * (bounded model questions with defaults) but NEVER decides the flow — order
 * and continuation belong to ExpressPipeline. Throw ExpressHalt for the one
 * legitimate user-facing stop; any other exception is a pipeline failure.
 */
interface ExpressPhase
{
    /** Stable identifier persisted as the run's `phase` while executing. */
    public function name(): string;

    /** Short user-visible progress line announced when the phase starts. */
    public function announce(ExpressContext $context): string;

    public function run(ExpressContext $context, PipelineRun $run): void;
}
