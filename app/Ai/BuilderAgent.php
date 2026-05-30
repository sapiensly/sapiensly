<?php

namespace App\Ai;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\MaxTokens;

/**
 * The Builder's chat agent. Identical to AnonymousAgent but pins max output
 * tokens to 32k.
 *
 * Why: the Anthropic gateway defaults max_tokens to 64,000 when the agent
 * declares no #[MaxTokens]. Claude Opus 4 caps output at 32,000, so a 64k
 * request is rejected with HTTP 400 ("max_tokens: 64000 > 32000"). 32,000 is
 * valid for every Claude 4.x model (Sonnet/Haiku allow up to 64k, Opus 32k)
 * and is far more than a manifest-editing turn ever needs.
 */
#[MaxTokens(32000)]
class BuilderAgent extends AnonymousAgent {}
