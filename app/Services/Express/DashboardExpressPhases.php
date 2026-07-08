<?php

namespace App\Services\Express;

use App\Services\Express\Contracts\ExpressPhase;
use App\Services\Express\Phases\AcquireObjectsPhase;
use App\Services\Express\Phases\CompilePhase;
use App\Services\Express\Phases\FitCheckPhase;
use App\Services\Express\Phases\RefineDashboardPhase;
use App\Services\Express\Phases\ResolveSourcePhase;
use App\Services\Express\Phases\SemanticGatesPhase;
use App\Services\Express\Phases\SuggestSpecPhase;
use App\Services\Express\Phases\VerifyRenderPhase;

/**
 * The canonical dashboard pipeline: source → fit → objects → spec → COMPILE
 * (deterministic dashboard banked) → semantics → REFINE (fold the gate outputs
 * in) → verify. Compiling BEFORE the model gates guarantees a working page even
 * if the enrichment dies; a fully-fallback run simply skips the refine. One
 * place defines the order so the job, the benchmark and the tests all match.
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
            app(CompilePhase::class),
            app(SemanticGatesPhase::class),
            app(RefineDashboardPhase::class),
            app(VerifyRenderPhase::class),
        ];
    }
}
