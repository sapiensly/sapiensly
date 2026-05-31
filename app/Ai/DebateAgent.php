<?php

namespace App\Ai;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\MaxTokens;

/**
 * The IA Debate agent. Identical to AnonymousAgent but pins max output tokens
 * to 32k. Used for participant arguments, moderator consensus assessments, and
 * the final synthesis.
 */
#[MaxTokens(32000)]
class DebateAgent extends AnonymousAgent {}
