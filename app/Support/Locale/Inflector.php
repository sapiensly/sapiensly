<?php

namespace App\Support\Locale;

use Illuminate\Support\Str;

/**
 * Locale-aware singularization for the human-facing labels the app scaffolder
 * derives from object names ("Agregar {singular}", detail-page titles, …).
 *
 * Laravel's Str::singular() is an English inflector, so it mangles Romance-language
 * plurals — "Proveedores" → "Proveedore", "Commandes" left untouched. Each
 * supported locale (es, pt, fr) gets a heuristic rule set covering the plurals
 * that show up as record-object names; English (and any other locale) falls back
 * to Str. Best-effort: correct on the common business nouns, not a full grammar —
 * a rare irregular (fr "souris", pt "lápis") may be over-singularized.
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

        return match ($noun === '' ? 'en' : $lang) {
            'es' => self::singularEs($noun),
            'pt' => self::singularPt($noun),
            'fr' => self::singularFr($noun),
            default => (string) Str::singular($noun),
        };
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

    /**
     * Best-effort Portuguese singular. Compound "X de Y" singularizes the head;
     * then suffix rules (nasal -ões/-ães/-ãos → -ão, -ns → -m, consonant -res/-zes,
     * the -l plurals -ais/-éis/-óis) with a "drop the trailing -s" default.
     */
    private static function singularPt(string $noun): string
    {
        if (str_contains($noun, ' ')) {
            [$head, $rest] = explode(' ', $noun, 2);

            return self::singularPt($head).' '.$rest;
        }

        $lower = mb_strtolower($noun, 'UTF-8');
        if (! str_ends_with($lower, 's')) {
            return $noun;
        }
        $len = mb_strlen($noun);

        // Nasal plurals → -ão (opções → opção, pães → pão, mãos → mão).
        if (str_ends_with($lower, 'ões') || str_ends_with($lower, 'ães') || str_ends_with($lower, 'ãos')) {
            return mb_substr($noun, 0, $len - 3, 'UTF-8').'ão';
        }
        // -ns → -m (homens → homem, ordens → ordem, jardins → jardim).
        if (str_ends_with($lower, 'ns')) {
            return mb_substr($noun, 0, $len - 2, 'UTF-8').'m';
        }
        // Consonant-stem -es (flores → flor, luzes → luz).
        if (str_ends_with($lower, 'res') || str_ends_with($lower, 'zes')) {
            return mb_substr($noun, 0, $len - 2, 'UTF-8');
        }
        // -l plurals: the vowel before -is names the singular ending.
        if (str_ends_with($lower, 'ais')) {
            return mb_substr($noun, 0, $len - 3, 'UTF-8').'al';
        }
        if (str_ends_with($lower, 'éis') || str_ends_with($lower, 'eis')) {
            return mb_substr($noun, 0, $len - 3, 'UTF-8').'el';
        }
        if (str_ends_with($lower, 'óis') || str_ends_with($lower, 'ois')) {
            return mb_substr($noun, 0, $len - 3, 'UTF-8').'ol';
        }
        if (str_ends_with($lower, 'uis')) {
            return mb_substr($noun, 0, $len - 3, 'UTF-8').'ul';
        }

        // Default: vowel-stem, drop -s (produtos → produto, faturas → fatura).
        return mb_substr($noun, 0, $len - 1, 'UTF-8');
    }

    /**
     * Best-effort French singular. Compound "X de Y" singularizes the head; then
     * the -x plurals (-eaux → -eau, -eux → -eu, -aux → -al), invariable -x/-z, and
     * a "drop the trailing -s" default for the ordinary -s plural.
     */
    private static function singularFr(string $noun): string
    {
        if (str_contains($noun, ' ')) {
            [$head, $rest] = explode(' ', $noun, 2);

            return self::singularFr($head).' '.$rest;
        }

        $lower = mb_strtolower($noun, 'UTF-8');
        $len = mb_strlen($noun);

        // -x plurals (most specific first): bateaux → bateau, jeux → jeu,
        // chevaux → cheval.
        if (str_ends_with($lower, 'eaux') || str_ends_with($lower, 'eux')) {
            return mb_substr($noun, 0, $len - 1, 'UTF-8');
        }
        if (str_ends_with($lower, 'aux')) {
            return mb_substr($noun, 0, $len - 3, 'UTF-8').'al';
        }
        // Other -x / -z are invariable (prix, choix, nez).
        if (str_ends_with($lower, 'x') || str_ends_with($lower, 'z')) {
            return $noun;
        }
        // The ordinary plural: drop the -s (commandes → commande, produits →
        // produit, factures → facture).
        if (str_ends_with($lower, 's')) {
            return mb_substr($noun, 0, $len - 1, 'UTF-8');
        }

        return $noun;
    }
}
