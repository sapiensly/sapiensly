<?php

namespace App\Services\Import;

/**
 * Decides what a column IS from the values in it.
 *
 * This is the difference between an import that leaves you twenty text columns
 * and one that leaves you a working app: a "Precio" column that arrives as
 * `currency` charts and sums; the same column as `string` does neither, and
 * nobody goes back to fix twenty of them by hand.
 *
 * The rule throughout is unanimity — a type is only claimed when EVERY
 * non-blank value in the column converts. One address in a phone column, and
 * the column is text. Guessing from a majority would corrupt the minority
 * silently, which is worse than a column the user retypes once.
 */
class ColumnProfiler
{
    /** Distinct values at or below which a column reads as a choice list. */
    private const SELECT_MAX_OPTIONS = 15;

    /** A column is only a choice list if values genuinely repeat. */
    private const SELECT_MIN_ROWS = 8;

    /** Length above which text is authored as a paragraph, not a line. */
    private const LONG_TEXT_LENGTH = 120;

    /** Header words that mark a number as money. */
    private const MONEY_WORDS = [
        'precio', 'costo', 'coste', 'total', 'monto', 'importe', 'subtotal', 'saldo',
        'salario', 'sueldo', 'pago', 'ingreso', 'venta', 'factura', 'price', 'cost',
        'amount', 'revenue', 'salary', 'balance', 'fee', 'payment',
    ];

    /**
     * @param  list<string|null>  $values
     */
    public function profile(string $header, array $values): ColumnProfile
    {
        $present = array_values(array_filter($values, fn (?string $v): bool => $v !== null));
        $distinct = array_values(array_unique($present));
        $samples = array_slice($distinct, 0, 3);
        $notes = [];

        // An empty column carries no evidence at all. Text is the type that
        // accepts whatever arrives later.
        if ($present === []) {
            return new ColumnProfile($header, 'string', 0, 0, notes: ['No values in this column — imported as text.']);
        }

        $counts = ['filled' => count($present), 'distinct' => count($distinct)];

        if ($this->allMatch($present, fn (string $v): bool => ValueCoercion::toBoolean($v) !== null)) {
            return new ColumnProfile($header, 'boolean', ...array_values($counts), samples: $samples);
        }

        if ($this->allMatch($present, fn (string $v): bool => filter_var($v, FILTER_VALIDATE_EMAIL) !== false)) {
            return new ColumnProfile($header, 'email', ...array_values($counts), samples: $samples);
        }

        if ($this->allMatch($present, fn (string $v): bool => preg_match('#^https?://\S+$#i', $v) === 1)) {
            return new ColumnProfile($header, 'url', ...array_values($counts), samples: $samples);
        }

        if ($this->looksLikePhone($present)) {
            return new ColumnProfile($header, 'phone', ...array_values($counts), samples: $samples);
        }

        // Dates BEFORE numbers: a column of "2026-01-15" is not a subtraction,
        // and a bare year column ("2024") is better as a number, which the
        // date check declines because it needs separators.
        if ($this->allMatch($present, fn (string $v): bool => $this->looksDateish($v))) {
            $dayFirst = $this->resolveDayFirst($present, $notes);
            $withTime = $this->anyMatch($present, fn (string $v): bool => preg_match('/\d[ T]\d{1,2}:\d{2}/', $v) === 1);

            if ($this->allMatch($present, fn (string $v): bool => ValueCoercion::toDate($v, $dayFirst, $withTime) !== null)) {
                return new ColumnProfile(
                    $header,
                    $withTime ? 'datetime' : 'date',
                    ...array_values($counts),
                    dayFirst: $dayFirst,
                    samples: $samples,
                    notes: $notes,
                );
            }
        }

        if ($this->allMatch($present, fn (string $v): bool => ValueCoercion::toNumber($v) !== null)) {
            return new ColumnProfile(
                $header,
                $this->isMoney($header, $present) ? 'currency' : 'number',
                ...array_values($counts),
                samples: $samples,
            );
        }

        // A multi-value column ("rojo, azul") beats a plain select: split first,
        // then ask whether the PARTS form a small vocabulary.
        if (($multi = $this->profileMultiSelect($header, $present, $counts, $samples)) !== null) {
            return $multi;
        }

        if ($this->isSelect($present, $distinct)) {
            return new ColumnProfile(
                $header,
                'single_select',
                ...array_values($counts),
                options: $this->options($distinct),
                samples: $samples,
                notes: ['Detected '.count($distinct).' repeating values — imported as a choice list.'],
            );
        }

        $longest = max(array_map(mb_strlen(...), $present));
        if ($longest > self::LONG_TEXT_LENGTH || $this->anyMatch($present, fn (string $v): bool => str_contains($v, "\n"))) {
            return new ColumnProfile($header, 'long_text', ...array_values($counts), samples: $samples);
        }

        return new ColumnProfile($header, 'string', ...array_values($counts), samples: $samples);
    }

    /**
     * A choice list needs enough rows for repetition to mean something, few
     * enough distinct values to be a vocabulary, and real reuse — 40 distinct
     * values in 45 rows is a name column, not a status.
     *
     * @param  list<string>  $present
     * @param  list<string>  $distinct
     */
    private function isSelect(array $present, array $distinct): bool
    {
        return count($present) >= self::SELECT_MIN_ROWS
            && count($distinct) <= self::SELECT_MAX_OPTIONS
            && count($distinct) < count($present) * 0.5
            && $this->allMatch($distinct, fn (string $v): bool => mb_strlen($v) <= 60);
    }

    /**
     * @param  list<string>  $present
     * @param  array{filled: int, distinct: int}  $counts
     * @param  list<string>  $samples
     */
    private function profileMultiSelect(string $header, array $present, array $counts, array $samples): ?ColumnProfile
    {
        foreach (ValueCoercion::LIST_SEPARATORS as $separator) {
            // Only worth considering when the separator is actually in use, and
            // in enough of the column to be the format rather than a stray comma.
            $withSeparator = array_filter($present, fn (string $v): bool => str_contains($v, $separator));
            if (count($withSeparator) < max(2, count($present) * 0.3)) {
                continue;
            }

            $parts = [];
            foreach ($present as $value) {
                foreach (ValueCoercion::splitList($value, $separator) ?? [] as $part) {
                    $parts[] = $part;
                }
            }

            $distinctParts = array_values(array_unique($parts));
            if ($distinctParts === [] || count($distinctParts) > self::SELECT_MAX_OPTIONS) {
                continue;
            }
            // Every part must be short — otherwise this is prose with commas.
            if (! $this->allMatch($distinctParts, fn (string $v): bool => mb_strlen($v) <= 40)) {
                continue;
            }

            return new ColumnProfile(
                $header,
                'multi_select',
                ...array_values($counts),
                options: $this->options($distinctParts),
                listSeparator: $separator,
                samples: $samples,
                notes: ["Cells hold several values separated by '{$separator}' — imported as a multiple-choice list."],
            );
        }

        return null;
    }

    /**
     * Phone numbers are digits with punctuation, 7-20 of them. The lower bound
     * keeps short codes and quantities out; requiring at least one non-digit or
     * a leading + keeps plain integers (which are numbers) out.
     *
     * @param  list<string>  $present
     */
    private function looksLikePhone(array $present): bool
    {
        return $this->allMatch($present, function (string $v): bool {
            $digits = preg_replace('/\D/', '', $v) ?? '';

            return strlen($digits) >= 7
                && strlen($digits) <= 20
                && preg_match('/^\+?[\d\s().\-]+$/', $v) === 1
                && (str_starts_with(trim($v), '+') || preg_match('/[\s().\-]/', $v) === 1);
        });
    }

    /**
     * Cheap gate before the real parse: something with date separators and a
     * plausible year, so "3.14" never reaches the date parser.
     */
    private function looksDateish(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1
            || preg_match('#^\d{1,2}[/.\-]\d{1,2}[/.\-]\d{2,4}#', $value) === 1;
    }

    /**
     * Settle d/m/Y vs m/d/Y for the whole column. A single value with a first
     * part above 12 proves day-first; one with a second part above 12 proves
     * month-first. With neither, the file cannot tell us — day-first is the
     * convention nearly everywhere outside the US, and the plan says so out
     * loud rather than deciding quietly.
     *
     * @param  list<string>  $present
     * @param  list<string>  $notes
     */
    private function resolveDayFirst(array $present, array &$notes): bool
    {
        foreach ($present as $value) {
            if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-]#', $value, $m) !== 1) {
                continue;
            }
            if ((int) $m[1] > 12) {
                return true;
            }
            if ((int) $m[2] > 12) {
                $notes[] = 'Dates read as month/day/year.';

                return false;
            }
        }

        // Only ambiguous if the column actually uses the slash form at all.
        if ($this->anyMatch($present, fn (string $v): bool => preg_match('#^\d{1,2}[/.\-]\d{1,2}[/.\-]#', $v) === 1)) {
            $notes[] = 'Every date fits both day/month and month/day — read as day/month/year. Check a row before importing.';
        }

        return true;
    }

    /**
     * @param  list<string>  $present
     */
    private function isMoney(string $header, array $present): bool
    {
        $normalized = mb_strtolower($header);
        foreach (self::MONEY_WORDS as $word) {
            if (str_contains($normalized, $word)) {
                return true;
            }
        }

        // A currency symbol in the values themselves is stronger than the name.
        return $this->anyMatch($present, fn (string $v): bool => preg_match('/[$€£¥]/u', $v) === 1);
    }

    /**
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private function options(array $values): array
    {
        sort($values);

        return array_map(fn (string $v): array => ['value' => $v, 'label' => $v], $values);
    }

    /**
     * @param  list<string>  $values
     */
    private function allMatch(array $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if (! $predicate($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $values
     */
    private function anyMatch(array $values, callable $predicate): bool
    {
        foreach ($values as $value) {
            if ($predicate($value)) {
                return true;
            }
        }

        return false;
    }
}
