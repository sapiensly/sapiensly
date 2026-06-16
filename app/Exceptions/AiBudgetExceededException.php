<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown by AiSpendGuard when an org has reached its AI spend budget for a
 * source (system / own). Surfaces as a clean 402 for HTTP/Inertia callers; the
 * streaming/queue paths catch it and show the message instead of a 500.
 */
class AiBudgetExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        public readonly float $spent,
        public readonly float $limit,
    ) {
        parent::__construct(sprintf(
            'AI spend budget reached for %s models (%.2f of %.2f used this period).',
            $source,
            $spent,
            $limit,
        ));
    }

    public function render(Request $request): Response
    {
        return response()->json([
            'message' => 'Your organization has reached its AI spend budget for this period. Contact an administrator to raise it.',
            'source' => $this->source,
        ], 402);
    }
}
