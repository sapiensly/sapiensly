<?php

namespace App\Services\Express;

use Illuminate\Support\Str;

/**
 * One rule, every gate: a label claiming a dimension («Pareto de Motivos»,
 * «Top Causas Raíz») must point at data that carries it. G-2a judges model
 * overrides with this; G-3 judges the verifier's renames with the SAME bar —
 * the observed failure mode was the exact same fabricated label walking in
 * through whichever door had no check.
 */
class LabelGrounding
{
    private const DIMENSION_WORDS = '/\b(causas?|motivos?|categor[ií]as?|prioridad(?:es)?|canal(?:es)?|reasons?|causes?|categor(?:y|ies)|priorit(?:y|ies)|channels?)\b/iu';

    /**
     * @param  array<string, mixed>|null  $object  the manifest object the block actually reads
     */
    public static function grounded(string $label, ?array $object): bool
    {
        $ascii = Str::lower(Str::ascii($label));
        if ($ascii === '' || preg_match_all(self::DIMENSION_WORDS, $ascii, $m) === 0) {
            return true;
        }
        if (! is_array($object)) {
            return true; // unknown target — feasibility checks elsewhere decide
        }

        $hay = Str::lower(Str::ascii(json_encode([
            $object['slug'] ?? '',
            $object['name'] ?? '',
            collect($object['fields'] ?? [])
                ->map(fn ($f): array => is_array($f) ? [(string) ($f['name'] ?? ''), (string) ($f['slug'] ?? '')] : [])
                ->all(),
            $object['source']['operations']['list']['arguments'] ?? [],
        ], JSON_UNESCAPED_UNICODE) ?: ''));

        foreach (array_unique($m[0]) as $word) {
            $w = Str::lower(Str::ascii((string) $word));
            $ok = DomainLexicon::expand(collect([$w]))->contains(
                fn (string $v): bool => str_contains($hay, $v)
                    || (mb_strlen($v) >= 5 && str_contains($hay, mb_substr($v, 0, 5))),
            );
            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}
