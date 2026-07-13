<?php

namespace App\Services\Analyst;

use Illuminate\Support\Str;

/**
 * The day something happened.
 *
 * A trend line says where a measure is going; it doesn't say that on the 18th of
 * May the backlog hit 412 — three standard deviations above its own mean — and
 * that someone should find out why. That reading is a statement, not a picture:
 * the chart already draws the spike, so what's missing is the sentence naming it.
 *
 * The outlier itself has been computed all along ({@see ComputedFactsBuilder}
 * `anomalia`: a 2σ peak with its date, value and z-score) and nothing ever read
 * it. This is the finder that does.
 */
class AnomalyFinder
{
    /** Beyond this, an outlier stops being noteworthy and starts being alarming. */
    private const LOUD_Z = 3.0;

    /** One anomaly per object, and never more than this across the board. */
    private const MAX = 2;

    /**
     * @param  array<string, array{object: array<string,mixed>, rows: list<array<string,mixed>>, facts: array<string,mixed>}>  $byObject
     * @return list<array<string, mixed>>
     */
    public function analyze(array $byObject, bool $es): array
    {
        $out = [];

        foreach ($byObject as $entry) {
            if (count($out) >= self::MAX) {
                break;
            }
            $anomaly = $entry['facts']['anomalia'] ?? null;
            if (! is_array($anomaly)) {
                continue;
            }

            $measure = (string) $anomaly['measure'];
            $date = (string) $anomaly['fecha'];
            $value = $anomaly['valor'];
            $mean = $anomaly['media'];
            $z = (float) $anomaly['z'];
            $above = ($anomaly['direccion'] ?? 'sobre') === 'sobre';
            $loud = $z >= self::LOUD_Z;

            $body = $es
                ? "El {$date}, ".Str::lower($measure)." llegó a {$value} — {$z}σ ".($above ? 'por encima' : 'por debajo')." de su media de {$mean}. No es ruido: vale entender qué pasó ese día."
                : "On {$date}, ".Str::lower($measure)." hit {$value} — {$z}σ ".($above ? 'above' : 'below')." its mean of {$mean}. That isn't noise: worth understanding what happened that day.";

            $out[] = [
                'kind' => 'anomaly',
                'kicker' => $es ? 'Anomalía · '.$date : 'Anomaly · '.$date,
                'title' => $es
                    ? Str::ucfirst(Str::lower($measure)).($above ? ' se disparó' : ' se desplomó')
                    : Str::ucfirst(Str::lower($measure)).($above ? ' spiked' : ' collapsed'),
                'why' => $body,
                'insight' => [
                    'type' => 'insight',
                    'title' => $es ? "Anomalía: {$date}" : "Anomaly: {$date}",
                    'body' => $body,
                    // A loud outlier is a risk to look at, not a conclusion to keep.
                    'variant' => $loud ? 'risk' : 'insight',
                ],
                'preview' => ['kind' => 'gauge', 'value' => (float) $value, 'target' => (float) $mean],
                'base' => 92,
                'flag' => $loud ? ['tone' => 'hot', 'text' => $z.'σ'] : null,
                'measure' => $measure,
            ];
        }

        return $out;
    }
}
