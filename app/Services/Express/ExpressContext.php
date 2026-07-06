<?php

namespace App\Services\Express;

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\Integration;
use App\Models\User;
use Closure;

/**
 * The mutable state a run's phases hand each other: what the user asked, what
 * the platform resolved and built so far, and the honest notes for the final
 * report (substitutions, unanswerable pieces, fallbacks). Phases read what the
 * previous ones left and write what the next ones need — no phase talks to
 * another except through this bag, which keeps every phase testable alone.
 */
class ExpressContext
{
    public ?Integration $integration = null;

    /** @var list<array<string, mixed>> catalog tools of the chosen integration */
    public array $catalogTools = [];

    /** @var array<string, array<string, mixed>> observed row shapes by tool ([] fields = summary-only, avoid) */
    public array $knownShapes = [];

    /** @var list<string> MCP tool names G-1 chose to read */
    public array $chosenTools = [];

    /** @var list<array<string, mixed>> honest substitutions {asked, using, reason} */
    public array $substitutions = [];

    /** @var list<array<string, mixed>> asked pieces the source can't answer {asked, reason} */
    public array $unanswerable = [];

    /** @var list<array<string, mixed>> authored connected objects (manifest nodes) */
    public array $objects = [];

    /** @var array<string, list<array<string, mixed>>> fetched rows per object id (for facts) */
    public array $rowsByObject = [];

    /** @var array<string, mixed>|null the suggested spec (F-3) */
    public ?array $spec = null;

    /** @var array<string, mixed> computed facts for insight writing */
    public array $facts = [];

    /** @var array<string, mixed> semantic gate outputs (title, purpose, insights, overrides) */
    public array $semantic = [];

    /** @var array<string, mixed>|null the compiled page {slug, path, name} */
    public ?array $page = null;

    /** @var list<array<string, mixed>> rendered-number summary from the render audit */
    public array $renderedSummary = [];

    /** @var list<string> plain-language notes for the final report */
    public array $notes = [];

    public ?Closure $onProgress = null;

    public function __construct(
        public readonly App $app,
        public readonly User $user,
        public readonly BuilderConversation $conversation,
        public readonly string $prompt,
        public readonly ?string $modelOverride = null,
    ) {}

    /** Emit a user-visible progress line (wired to the streaming placeholder). */
    public function progress(string $line): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($line);
        }
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }
}
