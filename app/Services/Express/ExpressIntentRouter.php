<?php

namespace App\Services\Express;

use App\Models\App;
use App\Models\Integration;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * G-0: routes a chat message into the Express pipeline when it clearly asks
 * to BUILD a DASHBOARD and the tenant has a live MCP source to build it from.
 * Deliberately conservative — heuristic only, and any ambiguity falls back to
 * the agentic chat (the status quo can never get worse). A plumbing-model
 * tiebreak for ambiguous phrasing is a future refinement.
 */
class ExpressIntentRouter
{
    private const DASHBOARD_WORDS = '/\b(dashboard|scoreboard|scorecard|tablero|reporte|kpis?|m[eé]tricas|anal[ií]tica|an[aá]lisis)\b/iu';

    private const BUILD_WORDS = '/\b(crea|constru|haz|genera|arma|dame|quiero|necesito|build|create|make)\w*/iu';

    /**
     * Signals the user wants the conversational path — never reroute. These are
     * about the PROCESS ("explícame", "paso a paso"), which is unambiguous.
     */
    private const OPT_OUT_WORDS = '/\b(conversando|paso a paso|manual|sin express|explica)\b/iu';

    /**
     * A message that OPENS with an interrogative is a question, not a brief.
     *
     * "cómo" and "por qué" used to sit in the opt-out list, and they defeated the
     * route from anywhere in the text — so a fifteen-line dashboard brief was sent
     * to the conversational builder because one of its bullets read "cómo venimos
     * hoy contra la semana pasada". They are ordinary Spanish interrogatives and
     * they belong in a real brief: "quiero un tablero … cómo vamos", "por qué cayó
     * el OTD" are things a director asks OF THE DATA, not of the builder.
     *
     * Anchored to the start, they keep their original job — "¿por qué mi tablero
     * está vacío?" is still a question — without stealing every brief that happens
     * to contain a question word.
     */
    private const OPT_OUT_OPENERS = '/^\s*[¿¡"\'«]*\s*(c[oó]mo|por qu[eé]|qu[eé] es|para qu[eé])\b/iu';

    /**
     * Nouns that name the thing being built when the ask is a whole APP, not a
     * dashboard: "una app / aplicación / sistema / plataforma / gestor / CRM /
     * ERP para …". Landing nouns ride the same handoff — the builder turn's
     * landing rule (1d-land) + design gate take over from there. Paired with a
     * build verb (and never a clean dashboard ask), these route to the full-app
     * builder handoff. Matched on accent-folded text.
     */
    private const APP_NOUN_WORDS = '/\b(app|aplicacion|sistema|plataforma|gestor|crm|erp|landing|pagina de aterrizaje|landing page)\b/i';

    public function shouldRunExpress(string $message, App $app): bool
    {
        if (! $this->enabled() || ! $this->messageAsksForDashboard($message)) {
            return false;
        }

        return $this->hasLiveMcpSource($app->organization_id, $app->user_id);
    }

    /**
     * G-0 for the full-app builder handoff from the general chat: a clear ask to
     * BUILD AN APP (a build verb over an app noun, or a thick data-model spec)
     * routes to the async builder — the chat provisions an app + conversation and
     * runs a real builder turn, then announces the app when it lands. Unlike the
     * dashboard route it needs NO live MCP source (an app carries its own data),
     * and it deliberately stands down for a clean dashboard ask so the two paths
     * stay disjoint. Gated behind its own flag so the rollout is independent.
     */
    public function shouldBuildAppForUser(string $message, User $user): bool
    {
        return $this->appAutorouteEnabled() && $this->messageAsksForApp($message);
    }

    /**
     * The same G-0 decision for a caller that has no app of its own — the
     * general chat. Scopes the live-source check to the acting user's tenant
     * instead of an app's owner, so a "build me a dashboard" chat message routes
     * to Express exactly when the Builder's own autoroute would.
     */
    public function shouldRunExpressForUser(string $message, User $user): bool
    {
        if (! $this->enabled() || ! $this->messageAsksForDashboard($message)) {
            return false;
        }

        return $this->hasLiveMcpSource($user->organization_id, $user->id);
    }

    private function enabled(): bool
    {
        return (bool) config('express.enabled') && (bool) config('express.autoroute');
    }

    private function appAutorouteEnabled(): bool
    {
        return (bool) config('express.enabled') && (bool) config('express.app_autoroute');
    }

    /**
     * The textual half of the app-build heuristic: a build verb over an app noun
     * (or a data-model spec), with the process opt-outs and a leading
     * interrogative removed, and standing down for a clean dashboard ask so the
     * dashboard route owns those. Conservative — any ambiguity falls back to the
     * conversational chat, exactly like the dashboard heuristic.
     */
    private function messageAsksForApp(string $message): bool
    {
        $text = Str::lower($message);
        // Fold accents for the verb + noun checks so "créame"/"aplicación" match
        // the ASCII patterns (the raw text is kept for the opt-out openers).
        $folded = Str::ascii($text);
        if (preg_match(self::OPT_OUT_WORDS, $text) === 1
            || preg_match(self::OPT_OUT_OPENERS, trim($text)) === 1
            || preg_match(self::BUILD_WORDS, $folded) !== 1) {
            return false;
        }

        // A clean "build me a dashboard" stays on the dashboard path; only an
        // app spec (which suppresses the dashboard route) or a non-dashboard
        // app noun reaches the app builder.
        if ($this->messageAsksForDashboard($text)) {
            return false;
        }

        return preg_match(self::APP_NOUN_WORDS, $folded) === 1
            || $this->describesAppBuild($text);
    }

    /**
     * The textual half of the G-0 heuristic: a clear build verb over a
     * dashboard word, with the process opt-outs ("paso a paso", a leading
     * interrogative) removed. Deliberately conservative — any ambiguity falls
     * back to the conversational path.
     */
    private function messageAsksForDashboard(string $message): bool
    {
        $text = Str::lower($message);
        if (preg_match(self::OPT_OUT_WORDS, $text) === 1
            || preg_match(self::OPT_OUT_OPENERS, trim($text)) === 1
            || $this->describesAppBuild($text)) {
            return false;
        }

        return (preg_match(self::DASHBOARD_WORDS, $text) === 1 || $this->typoedDashboardWord($text))
            && preg_match(self::BUILD_WORDS, $text) === 1;
    }

    /**
     * Data-model vocabulary a brief uses when it asks to build a whole APP, not a
     * dashboard: objects, fields, relations, pages, forms, roles, workflows. Two
     * distinct hits is the threshold — a real dashboard brief carries none (it
     * talks about metrics over existing data), while an app spec is thick with
     * them, so one incidental "campo" never suppresses a genuine dashboard route.
     *
     * @var list<string>
     */
    private const APP_BUILD_SIGNALS = [
        'objeto',           // objeto / objetos
        'campo',            // campo / campos
        'relacion',         // relación / relaciones (accents folded below)
        'pertenece a',      // belongs-to phrasing
        'workflow',
        'automatiz',        // automatización / automatizaciones
        'permiso',          // roles y permisos
        'pagina',           // página / páginas
        'formulario',
        'kanban',
        'crud',
        'entidad',          // entidad / entidades
        'modelo de datos',
        'sistema completo',
    ];

    /**
     * Whether the message clearly describes building an APP / data model rather
     * than a dashboard — in which case the autoroute must stand down and let the
     * agentic builder (which can actually build the app) take the turn. This is
     * the safe direction: over-suppressing only falls back to the normal builder.
     * Guards against a full app spec ("crea un sistema … con estos objetos, sus
     * campos, relaciones, páginas y automatizaciones") being hijacked into the
     * dashboard-over-MCP flow just because it also mentions "dashboard"/"KPIs".
     */
    private function describesAppBuild(string $text): bool
    {
        // Fold accents so "página"/"relación"/"automatización" match ASCII needles.
        $folded = Str::ascii($text);

        $hits = 0;
        foreach (self::APP_BUILD_SIGNALS as $needle) {
            if (str_contains($folded, $needle) && ++$hits >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the tenant has a live (non-draft) MCP connection to build from —
     * without one Express would halt immediately, so a chat turn serves the
     * conversation better. A null organization scopes to the personal owner.
     */
    private function hasLiveMcpSource(?string $organizationId, ?string $userId): bool
    {
        return Integration::query()
            ->where(function ($q) use ($organizationId, $userId) {
                $q->where('organization_id', $organizationId);
                if ($organizationId === null) {
                    $q->orWhere('user_id', $userId);
                }
            })
            ->where('is_mcp', true)
            ->where('status', '!=', 'draft')
            ->exists();
    }

    /**
     * Per-word edit-distance tolerance for the fuzzy dashboard words. Short
     * words get 1, long ones 2 — near-misses at those lengths are
     * overwhelmingly the intended word. The matcher also demands the same
     * first letter, cutting the worst real-word collisions ("deporte" vs
     * "reporte"). The survivors ("recorte", "storyboard") still need a build
     * verb and a live source, and a stray reroute halts honestly downstream.
     *
     * @var array<string, int>
     */
    private const FUZZY_WORDS = [
        'dashboard' => 2,
        'scoreboard' => 2,
        'scorecard' => 2,
        'tablero' => 1,
        'reporte' => 1,
    ];

    /**
     * Typo tolerance for the flagship words only: "dahsboard" (observed twice
     * in prod, defeating the route both times) must count as "dashboard".
     */
    private function typoedDashboardWord(string $text): bool
    {
        foreach (preg_split('/[^a-z]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            foreach (self::FUZZY_WORDS as $target => $tolerance) {
                if ($word[0] === $target[0]
                    && abs(strlen($word) - strlen($target)) <= $tolerance
                    && levenshtein($word, (string) $target) <= $tolerance) {
                    return true;
                }
            }
        }

        return false;
    }
}
