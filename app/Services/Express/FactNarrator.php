<?php

namespace App\Services\Express;

/**
 * Turns computed facts ({@see ComputedFactsBuilder}) into one-line, real-number
 * sentences and stamps them onto insight cards — the deterministic narrator.
 * The suggester uses it so every board is born with factual insight bodies
 * (bank-first compiles BEFORE the model gates, so what the suggester writes is
 * what ships when the model can't answer); the semantic gate only REPLACES
 * them when the model actually responds.
 */
class FactNarrator
{
    /**
     * Give EACH card a DISTINCT real number drawn from the computed facts — a
     * leading measure's average/max, a boolean rate, a category concentration,
     * a 7-day trend — instead of stamping every card with the same "Registros
     * analizados: N" (uninformative, and on a weekly series N is just the
     * bucket count). Only when the fact pool runs dry does a card fall back to
     * the row-count line.
     *
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, mixed>  $facts
     * @return list<array<string, mixed>>
     */
    public function narrate(array $cards, array $facts): array
    {
        $pool = $this->factSentences($facts);
        $generic = 'Registros analizados: '.($facts['row_count'] ?? 0).'.';

        return collect($cards)->map(function (array $card, int $i) use ($pool, $generic): array {
            $fact = $pool[$i] ?? $generic;
            $card['body'] = trim(($card['body'] ?? '').' '.$fact);

            return $card;
        })->values()->all();
    }

    /**
     * A prioritised, DISTINCT set of one-line facts read straight from the
     * computed aggregates: leading measures first (average + peak), then rates,
     * then category concentration, then recent trend. Each is a real number a
     * card can carry, so a model-less build still narrates specifics.
     *
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    public function factSentences(array $facts): array
    {
        $pool = [];
        // Deltas first — "94 vs 80 (+18%)" is the strongest sentence a card
        // can carry; static aggregates follow.
        $pop = $facts['vs_periodo_anterior'] ?? [];
        $vsLabel = ($pop['base'] ?? '') === 'mitades'
            ? 'vs la primera mitad del periodo'
            : 'vs el periodo anterior';
        foreach ($pop['measures'] ?? [] as $name => $d) {
            if (! is_array($d)) {
                continue;
            }
            $sign = ($d['delta_pct'] ?? 0) >= 0 ? '+' : '';
            $pool[] = "«{$name}»: {$this->num($d['actual'] ?? null)} {$vsLabel} ({$sign}{$this->num($d['delta_pct'] ?? null)}%).";
        }
        foreach ($facts['numeric'] ?? [] as $name => $n) {
            if (! is_array($n)) {
                continue;
            }
            $pool[] = "«{$name}»: promedio {$this->num($n['avg'] ?? null)}, máximo {$this->num($n['max'] ?? null)}.";
        }
        foreach ($facts['rates'] ?? [] as $name => $r) {
            if (is_array($r) && isset($r['rate_pct'])) {
                $pool[] = "«{$name}»: {$this->num($r['rate_pct'])}% de los registros.";
            }
        }
        foreach ($facts['top_values'] ?? [] as $name => $t) {
            if (is_array($t) && isset($t['top'])) {
                $pool[] = "«{$name}»: «{$t['top']}» concentra {$this->num($t['share_pct'] ?? null)}% ({$this->num($t['count'] ?? null)}).";
            }
        }
        foreach ($facts['trend'] ?? [] as $name => $tr) {
            if (! is_array($tr) || ! isset($tr['last_7d'])) {
                continue;
            }
            $dir = ($tr['direction'] ?? 0) > 0 ? 'al alza' : (($tr['direction'] ?? 0) < 0 ? 'a la baja' : 'estable');
            $pool[] = "«{$name}»: {$this->num($tr['last_7d'])} en los últimos 7 días vs {$this->num($tr['previous_7d'] ?? 0)} previos ({$dir}).";
        }

        return array_values(array_unique($pool));
    }

    /** Compact number: integers plain, floats trimmed of trailing zeros. */
    private function num(mixed $value): string
    {
        if (! is_numeric($value)) {
            return (string) $value;
        }
        $float = (float) $value;

        return $float === floor($float)
            ? (string) (int) $float
            : rtrim(rtrim(number_format($float, 2, '.', ''), '0'), '.');
    }
}
