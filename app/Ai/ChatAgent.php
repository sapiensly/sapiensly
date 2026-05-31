<?php

namespace App\Ai;

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Attributes\MaxTokens;

/**
 * The general Chat agent. Identical to AnonymousAgent but pins max output
 * tokens to 32k — valid for every Claude 4.x model (Opus caps at 32k) and
 * a sane ceiling for a single chat turn.
 */
#[MaxTokens(32000)]
class ChatAgent extends AnonymousAgent {}
