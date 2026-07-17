<?php

namespace App\Support\Locale;

use Illuminate\Support\Str;

/**
 * Locale-aware singularization for the human-facing labels the app scaffolder
 * derives from object names ("Agregar {singular}", detail-page titles, …).
 *
 * Laravel's Str::singular() is an English inflector, so it mangles Spanish
 * plurals — "Proveedores" → "Proveedore", "Órdenes de Compra" left untouched.
 * For 'es' this applies a heuristic Spanish rule set covering the plurals that
 * show up as record-object names; every other locale falls back to Str.
 */
class Inflector
{
    /** Accented → plain vowel, both cases, for normalizing esdrújula stems (órden → orden). */
    private const DEACCENT = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
    ];

    public static function singular(string $noun, string $lang = 'en'): string
    {
        $noun = trim($noun);

        if ($noun === '' || $lang !== 'es') {
            return (string) Str::singular($noun);
        }

        return self::singularEs($noun);
    }

    /**
     * Best-effort Spanish singular. Handles compound "X de Y" names by
     * singularizing only the head noun, then applies suffix rules (most
     * specific first) with a "drop the trailing -s" default.
     */
    private static function singularEs(string $noun): string
    {
        // Compound like "Órdenes de Compra": singularize the head noun only.
        if (str_contains($noun, ' ')) {
            [$head, $rest] = explode(' ', $noun, 2);

            return self::singularEs($head).' '.$rest;
        }

        $lower = mb_strtolower($noun, 'UTF-8');

        // Already singular (Spanish plurals end in -s).
        if (! str_ends_with($lower, 's')) {
            return $noun;
        }

        $len = mb_strlen($noun);

        // -ces → -z (luces → luz, raíces → raíz).
        if (str_ends_with($lower, 'ces')) {
            return mb_substr($noun, 0, $len - 3, 'UTF-8').'z';
        }

        // -iones → -ión (direcciones → dirección, camiones → camión).
        if (str_ends_with($lower, 'iones')) {
            return mb_substr($noun, 0, $len - 5, 'UTF-8').'ión';
        }

        // Consonant-stem plurals formed with -es: drop the -es.
        // The stem's ending disambiguates these from vowel-stem plurals
        // ("clientes" → "cliente"), which the default branch handles.
        $consonantEsSuffixes = ['ores', 'ales', 'eles', 'iles', 'oles', 'ules', 'ades', 'udes', 'anes', 'ines', 'unes'];
        foreach ($consonantEsSuffixes as $suffix) {
            if (str_ends_with($lower, $suffix)) {
                return mb_substr($noun, 0, $len - 2, 'UTF-8');
            }
        }

        // -enes → -en, dropping any esdrújula accent (órdenes → orden,
        // imágenes → imagen). Trades a missing accent on aguda words like
        // "almacén" for correctness on the common business nouns.
        if (str_ends_with($lower, 'enes')) {
            return strtr(mb_substr($noun, 0, $len - 2, 'UTF-8'), self::DEACCENT);
        }

        // Default: vowel-stem plural, just drop the -s
        // (productos → producto, categorías → categoría, clientes → cliente).
        return mb_substr($noun, 0, $len - 1, 'UTF-8');
    }
}
