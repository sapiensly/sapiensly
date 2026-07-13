<?php

namespace App\Services\Express;

use App\Models\App;
use App\Models\Integration;
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

    public function shouldRunExpress(string $message, App $app): bool
    {
        if (! config('express.enabled') || ! config('express.autoroute')) {
            return false;
        }

        $text = Str::lower($message);
        if (preg_match(self::OPT_OUT_WORDS, $text) === 1
            || preg_match(self::OPT_OUT_OPENERS, trim($text)) === 1) {
            return false;
        }
        if ((preg_match(self::DASHBOARD_WORDS, $text) !== 1 && ! $this->typoedDashboardWord($text))
            || preg_match(self::BUILD_WORDS, $text) !== 1) {
            return false;
        }

        // Without a live source Express would halt immediately — a chat turn
        // serves that conversation better.
        return Integration::query()
            ->where(function ($q) use ($app) {
                $q->where('organization_id', $app->organization_id);
                if ($app->organization_id === null) {
                    $q->orWhere('user_id', $app->user_id);
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
