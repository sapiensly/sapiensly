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

    /**
     * EXTRA acquisitions of an already-chosen tool with a different enum value
     * for one of its arguments — {tool, arguments, cut}. "Motivos y causa
     * raíz" reads get-tickets-by-dimension twice: the default cut (category)
     * via chosenTools plus {dimension: cause} here.
     *
     * @var list<array{tool: string, arguments: array<string, string>, cut: string}>
     */
    public array $chosenCuts = [];

    /** @var list<array<string, mixed>> honest substitutions {asked, using, reason} */
    public array $substitutions = [];

    /** @var list<array<string, mixed>> asked pieces the source can't answer {asked, reason} */
    public array $unanswerable = [];

    /** @var list<array<string, mixed>> authored connected objects (manifest nodes) */
    public array $objects = [];

    /** @var array<string, list<array<string, mixed>>> fetched rows per object id (for facts) */
    public array $rowsByObject = [];

    /**
     * Raw rows of each object's PREVIOUS window (same tool, from/to shifted
     * back one span) — fuels period-over-period deltas in the computed facts.
     * Empty for objects without window arguments.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public array $previousRowsByObject = [];

    /** @var array<string, mixed>|null the suggested spec (F-3) */
    public ?array $spec = null;

    /** @var array<string, mixed> computed facts for insight writing */
    public array $facts = [];

    /** @var array<string, mixed> semantic gate outputs (title, purpose, insights, overrides) */
    public array $semantic = [];

    /**
     * Provider circuit-breaker: set true the instant ONE gate call hangs
     * (a real provider timeout) in this run. Every later gate then skips the
     * model entirely and uses its deterministic default — a hung provider costs
     * ONE 45s window for the whole build, not 45s per gate.
     */
    public bool $providerHung = false;

    /**
     * Economy mode fired: the deterministic fit was unambiguous, so every
     * model gate is skipped for this run (semantics stay at the suggested
     * defaults; the post-hoc verifier is not dispatched).
     */
    public bool $economyMode = false;

    /**
     * Set true once the semantic gates actually shaped the dashboard (an
     * accepted override, a model-written voice, or model-narrated insights).
     * When it stays false — every gate fell back — the deterministic page
     * already banked is the final one, so the refine step skips a redundant
     * identical version.
     */
    public bool $semanticEnriched = false;

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
        // A phase can announce '' to stay silent (e.g. the refine step when
        // there's nothing to refine) — never surface a blank line.
        if ($this->onProgress !== null && trim($line) !== '') {
            ($this->onProgress)($line);
        }
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    /** Trip the run-wide provider breaker after a gate hangs (see $providerHung). */
    public function markProviderHung(): void
    {
        $this->providerHung = true;
    }
}
