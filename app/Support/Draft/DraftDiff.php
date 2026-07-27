<?php

namespace App\Support\Draft;

/**
 * What a machine-generated draft would do to what a human already wrote.
 *
 * Both books that can be drafted from an organization's website — the
 * Contextbook and the Brandbook — share one rule: **the draft never writes.**
 * It proposes, and any field that already holds content requires an explicit
 * decision before it is replaced. Filling a field the human left empty is not
 * replacing; overwriting one they filled is, and that always asks.
 *
 * This exists as a shared value object rather than a rule repeated in two
 * services because "ask before overwriting" repeated twice is "ask before
 * overwriting" implemented once and forgotten once.
 *
 * Each entry is one field, labelled:
 *  - `new`      — the target is empty; safe to apply without asking.
 *  - `conflict` — the target holds something different; MUST be confirmed.
 *  - `same`     — the draft agrees with what is stored; nothing to do.
 */
final class DraftDiff
{
    public const NEW = 'new';

    public const CONFLICT = 'conflict';

    public const SAME = 'same';

    /**
     * @param  list<array{field: string, status: string, current: mixed, proposed: mixed}>  $entries
     */
    private function __construct(public readonly array $entries) {}

    /**
     * Compare a proposal against what is stored. Fields the draft has nothing to
     * say about are absent entirely — a draft that read no logo must never be
     * read as "clear the logo".
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $proposed
     */
    public static function between(array $current, array $proposed): self
    {
        $entries = [];

        foreach ($proposed as $field => $value) {
            if (self::isBlank($value)) {
                continue;
            }

            $existing = $current[$field] ?? null;

            $entries[] = [
                'field' => $field,
                'status' => match (true) {
                    self::isBlank($existing) => self::NEW,
                    self::equals($existing, $value) => self::SAME,
                    default => self::CONFLICT,
                },
                'current' => $existing,
                'proposed' => $value,
            ];
        }

        return new self($entries);
    }

    /**
     * The fields that can be applied without asking: the human left them empty.
     *
     * @return array<string, mixed>
     */
    public function additions(): array
    {
        return $this->valuesWithStatus(self::NEW);
    }

    /**
     * The fields that would overwrite something a human wrote. Never apply these
     * without an explicit per-field decision — that is the whole point of this
     * class.
     *
     * @return array<string, mixed>
     */
    public function conflicts(): array
    {
        return $this->valuesWithStatus(self::CONFLICT);
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts() !== [];
    }

    /** True when the draft would change nothing at all. */
    public function isEmpty(): bool
    {
        return $this->additions() === [] && $this->conflicts() === [];
    }

    /**
     * The shape the UI reviews: every field the draft has an opinion about, so
     * the user sees what would be kept as well as what would be replaced.
     *
     * @return list<array{field: string, status: string, current: mixed, proposed: mixed}>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * Apply a draft to stored values, taking ONLY the additions plus the
     * conflicts the caller names. A field not listed in $acceptedConflicts keeps
     * what the human wrote — the default is always "keep mine".
     *
     * @param  array<string, mixed>  $current
     * @param  list<string>  $acceptedConflicts
     * @return array<string, mixed>
     */
    public function applyTo(array $current, array $acceptedConflicts = []): array
    {
        $result = array_merge($current, $this->additions());

        foreach ($this->conflicts() as $field => $value) {
            if (in_array($field, $acceptedConflicts, true)) {
                $result[$field] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function valuesWithStatus(string $status): array
    {
        $values = [];
        foreach ($this->entries as $entry) {
            if ($entry['status'] === $status) {
                $values[$entry['field']] = $entry['proposed'];
            }
        }

        return $values;
    }

    /** Null, empty string, whitespace and empty list all mean "nothing there". */
    private static function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private static function equals(mixed $a, mixed $b): bool
    {
        if (is_string($a) && is_string($b)) {
            return strcasecmp(trim($a), trim($b)) === 0;
        }

        return $a == $b;
    }
}
