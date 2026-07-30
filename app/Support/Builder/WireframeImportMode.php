<?php

namespace App\Support\Builder;

use App\Support\Landing\LandingArtifact;

/**
 * What the user wants done with the thing they imported.
 *
 * The two answers pull in opposite directions and always did — the app path asks
 * for objects and table/kanban/stat blocks, which are exactly the blocks
 * ManifestValidator rejects on a landing surface — but until now the choice was
 * inferred from the artifact alone ({@see LandingArtifact}).
 * That guesses well for a design export and badly for a URL, where the evidence
 * arrives after the guess: an SPA's static HTML is a mount point, so a real
 * marketing page looked exactly like an unstyled fragment.
 *
 * So the user gets to say it:
 *
 *   REPLICA      — copy this page. The evidence is the design (rendered DOM,
 *                  stylesheet, screenshot) and the output is a landing.
 *   INSPIRATION  — build me an app that works like this. The evidence is the
 *                  structure, and the look is a reference, not a specification.
 *   AUTO         — decide from the artifact, as before.
 */
enum WireframeImportMode: string
{
    case Auto = 'auto';
    case Replica = 'replica';
    case Inspiration = 'inspiration';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Auto;
    }

    /**
     * Whether this import reproduces a designed page.
     *
     * @param  bool  $detected  what the artifact itself suggests
     * @param  bool  $appIsLanding  the app being imported into is already a landing
     */
    public function reproducesDesign(bool $detected, bool $appIsLanding = false): bool
    {
        return match ($this) {
            self::Replica => true,
            // The surface wins over the request: a landing app cannot hold the
            // blocks the app path would build, so "inspiration" there would
            // produce a manifest the validator refuses.
            self::Inspiration => $appIsLanding,
            self::Auto => $detected || $appIsLanding,
        };
    }

    /**
     * Whether to collect design evidence (stylesheet, per-element rules, icons)
     * rather than a structural sample. Auto defers to the artifact.
     */
    public function wantsDesignEvidence(bool $detected): bool
    {
        return match ($this) {
            self::Replica => true,
            self::Inspiration => false,
            self::Auto => $detected,
        };
    }
}
