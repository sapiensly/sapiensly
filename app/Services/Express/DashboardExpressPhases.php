<?php

namespace App\Services\Express;

use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\Phases\AcquireObjectsPhase;
use App\Services\Express\Phases\CompilePhase;
use App\Services\Express\Phases\FitCheckPhase;
use App\Services\Express\Phases\ResolveSourcePhase;
use App\Services\Express\Phases\SemanticGatesPhase;
use App\Services\Express\Phases\SuggestSpecPhase;

/**
 * The canonical dashboard pipeline: source → fit → objects → spec → semantics
 * → compile. One place defines the order so the job, the benchmark and the
 * tests all run the same flow.
 */
class DashboardExpressPhases
{
    /**
     * @return list<ExpressPhase>
     */
    public static function make(): array
    {
        return [
            app(ResolveSourcePhase::class),
            app(FitCheckPhase::class),
            app(AcquireObjectsPhase::class),
            app(SuggestSpecPhase::class),
            app(SemanticGatesPhase::class),
            app(CompilePhase::class),
        ];
    }
}
