<?php

namespace App\Services\Slides;

/**
 * Validates a deck manifest — the JSON a model (or user) authors to define a
 * presentation. The LLM never writes slide HTML: it writes this constrained
 * structure and the frontend renders it deterministically, which is what keeps
 * every deck visually consistent.
 *
 * Density budgets (max items / max characters) are enforced HERE, in the data
 * layer, so overflow is impossible by construction rather than patched in CSS.
 * Error strings are written for an LLM to act on: they name the exact path and
 * the allowed shape, so a failed create can be corrected on retry.
 */
class DeckValidator
{
    public const THEMES = ['executive', 'dark', 'minimal', 'bold'];

    public const LAYOUTS = [
        'title', 'section', 'bullets', 'two_column', 'big_number',
        'metrics', 'chart', 'quote', 'timeline', 'table', 'closing',
    ];

    public const TIMELINE_STATUSES = ['done', 'active', 'upcoming'];

    public const CHART_TYPES = ['bar', 'hbar', 'line', 'area', 'pie', 'donut', 'radar'];

    public const AGGREGATIONS = ['count', 'sum', 'avg', 'min', 'max'];

    public const BUCKETS = ['day', 'week', 'month', 'quarter', 'year'];

    private const MAX_SLIDES = 40;

    /**
     * Validate a decoded manifest. Returns a flat list of error strings —
     * empty when the deck is valid.
     *
     * @param  array<string, mixed>  $deck
     * @return list<string>
     */
    public function validate(array $deck): array
    {
        $errors = [];

        $this->requireText($deck, 'title', 120, $errors);

        $theme = $deck['theme'] ?? 'executive';
        if (! in_array($theme, self::THEMES, true)) {
            $errors[] = 'theme: must be one of '.implode(', ', self::THEMES).'.';
        }

        $slides = $deck['slides'] ?? null;
        if (! is_array($slides) || $slides === [] || array_is_list($slides) === false) {
            $errors[] = 'slides: must be a non-empty array of slide objects.';

            return $errors;
        }
        if (count($slides) > self::MAX_SLIDES) {
            $errors[] = 'slides: at most '.self::MAX_SLIDES.' slides.';
        }

        foreach ($slides as $i => $slide) {
            if (! is_array($slide)) {
                $errors[] = "slides.{$i}: must be an object.";

                continue;
            }
            $this->validateSlide($slide, "slides.{$i}", $errors);
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $slide
     * @param  list<string>  $errors
     */
    private function validateSlide(array $slide, string $path, array &$errors): void
    {
        $layout = $slide['layout'] ?? null;
        if (! in_array($layout, self::LAYOUTS, true)) {
            $errors[] = "{$path}.layout: must be one of ".implode(', ', self::LAYOUTS).'.';

            return;
        }

        $this->optionalText($slide, 'notes', 500, $path, $errors);

        match ($layout) {
            'title' => $this->validateTitle($slide, $path, $errors),
            'section' => $this->validateSection($slide, $path, $errors),
            'bullets' => $this->validateBullets($slide, $path, $errors),
            'two_column' => $this->validateTwoColumn($slide, $path, $errors),
            'big_number' => $this->validateBigNumber($slide, $path, $errors),
            'metrics' => $this->validateMetrics($slide, $path, $errors),
            'chart' => $this->validateChart($slide, $path, $errors),
            'quote' => $this->validateQuote($slide, $path, $errors),
            'timeline' => $this->validateTimeline($slide, $path, $errors),
            'table' => $this->validateTable($slide, $path, $errors),
            'closing' => $this->validateClosing($slide, $path, $errors),
        };
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateTitle(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'title', 80, $errors, $path);
        $this->optionalText($slide, 'subtitle', 140, $path, $errors);
        $this->optionalText($slide, 'meta', 60, $path, $errors);
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateSection(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'title', 70, $errors, $path);
        $this->optionalText($slide, 'kicker', 40, $path, $errors);
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateBullets(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'title', 70, $errors, $path);
        $this->optionalText($slide, 'kicker', 40, $path, $errors);
        $this->stringList($slide, 'bullets', 2, 5, 110, $path, $errors, required: true);
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateTwoColumn(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'title', 70, $errors, $path);
        foreach (['left', 'right'] as $side) {
            $column = $slide[$side] ?? null;
            if (! is_array($column)) {
                $errors[] = "{$path}.{$side}: required object {heading, items}.";

                continue;
            }
            $this->requireText($column, 'heading', 40, $errors, "{$path}.{$side}");
            $this->stringList($column, 'items', 1, 4, 90, "{$path}.{$side}", $errors, required: true);
        }
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateBigNumber(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'value', 20, $errors, $path);
        $this->requireText($slide, 'label', 80, $errors, $path);
        $this->optionalText($slide, 'kicker', 40, $path, $errors);
        $this->optionalText($slide, 'delta', 30, $path, $errors);
        $this->optionalText($slide, 'context', 140, $path, $errors);
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateMetrics(array $slide, string $path, array &$errors): void
    {
        $this->optionalText($slide, 'title', 70, $path, $errors);
        $items = $slide['items'] ?? null;
        if (! is_array($items) || count($items) < 2 || count($items) > 4) {
            $errors[] = "{$path}.items: 2 to 4 items of {value, label, delta?}.";

            return;
        }
        foreach (array_values($items) as $i => $item) {
            if (! is_array($item)) {
                $errors[] = "{$path}.items.{$i}: must be an object.";

                continue;
            }
            // A live-bound metric resolves its value at present-time; the
            // static `value` then serves as an optional fallback.
            if (isset($item['value_source'])) {
                $this->validateValueSource($item['value_source'], "{$path}.items.{$i}.value_source", $errors);
                $this->optionalText($item, 'value', 14, "{$path}.items.{$i}", $errors);
            } else {
                $this->requireText($item, 'value', 14, $errors, "{$path}.items.{$i}");
            }
            $this->requireText($item, 'label', 40, $errors, "{$path}.items.{$i}");
            $this->optionalText($item, 'delta', 20, "{$path}.items.{$i}", $errors);
        }
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateChart(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'title', 70, $errors, $path);
        $this->optionalText($slide, 'takeaway', 120, $path, $errors);

        $type = $slide['chart_type'] ?? null;
        if (! in_array($type, self::CHART_TYPES, true)) {
            $errors[] = "{$path}.chart_type: must be one of ".implode(', ', self::CHART_TYPES).'.';
        }

        // A live-bound chart resolves labels + series from app data at
        // present-time; static labels/series then serve as an optional fallback.
        if (isset($slide['data_source'])) {
            $this->validateDataSource($slide['data_source'], "{$path}.data_source", $errors);
            if (! isset($slide['labels']) && ! isset($slide['series'])) {
                return;
            }
        }

        $labels = $slide['labels'] ?? null;
        if (! is_array($labels) || count($labels) < 2 || count($labels) > 12
            || array_filter($labels, fn ($l) => ! is_string($l) || $l === '' || mb_strlen($l) > 20) !== []) {
            $errors[] = "{$path}.labels: 2 to 12 non-empty strings of at most 20 chars.";

            return;
        }

        $series = $slide['series'] ?? null;
        // Part-to-whole forms read as ONE ring/disc; every other form compares up to 3 series.
        $maxSeries = in_array($type, ['donut', 'pie'], true) ? 1 : 3;
        if (! is_array($series) || count($series) < 1 || count($series) > $maxSeries) {
            $errors[] = "{$path}.series: 1 to {$maxSeries} series of {name, data} for chart_type ".(is_string($type) ? $type : '?').'.';

            return;
        }
        foreach (array_values($series) as $i => $s) {
            if (! is_array($s)) {
                $errors[] = "{$path}.series.{$i}: must be an object.";

                continue;
            }
            $this->requireText($s, 'name', 30, $errors, "{$path}.series.{$i}");
            $data = $s['data'] ?? null;
            if (! is_array($data) || count($data) !== count($labels)
                || array_filter($data, fn ($v) => ! is_int($v) && ! is_float($v)) !== []) {
                $errors[] = "{$path}.series.{$i}.data: array of numbers, exactly one per label (".count($labels).').';
            }
        }
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateQuote(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'quote', 240, $errors, $path);
        $this->optionalText($slide, 'attribution', 60, $path, $errors);
        $this->optionalText($slide, 'role', 60, $path, $errors);
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateTimeline(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'title', 70, $errors, $path);
        $this->optionalText($slide, 'kicker', 40, $path, $errors);

        $items = $slide['items'] ?? null;
        if (! is_array($items) || count($items) < 2 || count($items) > 6) {
            $errors[] = "{$path}.items: 2 to 6 items of {label, title, description?, status?}.";

            return;
        }
        foreach (array_values($items) as $i => $item) {
            if (! is_array($item)) {
                $errors[] = "{$path}.items.{$i}: must be an object.";

                continue;
            }
            $this->requireText($item, 'label', 20, $errors, "{$path}.items.{$i}");
            $this->requireText($item, 'title', 60, $errors, "{$path}.items.{$i}");
            $this->optionalText($item, 'description', 90, "{$path}.items.{$i}", $errors);

            $status = $item['status'] ?? null;
            if ($status !== null && ! in_array($status, self::TIMELINE_STATUSES, true)) {
                $errors[] = "{$path}.items.{$i}.status: must be one of ".implode(', ', self::TIMELINE_STATUSES).'.';
            }
        }
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateTable(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'title', 70, $errors, $path);

        $columns = $slide['columns'] ?? null;
        if (! is_array($columns) || count($columns) < 2 || count($columns) > 4
            || array_filter($columns, fn ($c) => ! is_string($c) || trim($c) === '' || mb_strlen($c) > 24) !== []) {
            $errors[] = "{$path}.columns: 2 to 4 non-empty strings of at most 24 chars.";

            return;
        }

        $rows = $slide['rows'] ?? null;
        if (! is_array($rows) || count($rows) < 1 || count($rows) > 5) {
            $errors[] = "{$path}.rows: 1 to 5 rows (arrays of cells).";

            return;
        }
        foreach (array_values($rows) as $i => $row) {
            if (! is_array($row) || count($row) !== count($columns)) {
                $errors[] = "{$path}.rows.{$i}: exactly one cell per column (".count($columns).').';

                continue;
            }
            foreach (array_values($row) as $j => $cell) {
                if (! is_string($cell) || mb_strlen($cell) > 40) {
                    $errors[] = "{$path}.rows.{$i}.{$j}: string of at most 40 characters.";
                }
            }
        }
    }

    /** @param array<string, mixed> $slide @param list<string> $errors */
    private function validateClosing(array $slide, string $path, array &$errors): void
    {
        $this->requireText($slide, 'title', 80, $errors, $path);
        $this->optionalText($slide, 'subtitle', 140, $path, $errors);
        $this->optionalText($slide, 'cta', 40, $path, $errors);
        $this->stringList($slide, 'bullets', 1, 3, 80, $path, $errors, required: false);
    }

    /**
     * A chart's live data binding: grouped aggregation over an app object.
     *
     * @param  list<string>  $errors
     */
    private function validateDataSource(mixed $source, string $path, array &$errors): void
    {
        if (! is_array($source)) {
            $errors[] = "{$path}: must be an object {app_slug, object, group_by, aggregation, field?, bucket?}.";

            return;
        }
        $this->requireText($source, 'app_slug', 100, $errors, $path);
        $this->requireText($source, 'object', 100, $errors, $path);
        $this->requireText($source, 'group_by', 100, $errors, $path);
        $this->validateAggregation($source, $path, $errors);

        $bucket = $source['bucket'] ?? null;
        if ($bucket !== null && ! in_array($bucket, self::BUCKETS, true)) {
            $errors[] = "{$path}.bucket: must be one of ".implode(', ', self::BUCKETS).'.';
        }
    }

    /**
     * A metric's live value binding: a single aggregation over an app object.
     *
     * @param  list<string>  $errors
     */
    private function validateValueSource(mixed $source, string $path, array &$errors): void
    {
        if (! is_array($source)) {
            $errors[] = "{$path}: must be an object {app_slug, object, aggregation, field?}.";

            return;
        }
        $this->requireText($source, 'app_slug', 100, $errors, $path);
        $this->requireText($source, 'object', 100, $errors, $path);
        $this->validateAggregation($source, $path, $errors);
    }

    /** @param array<string, mixed> $source @param list<string> $errors */
    private function validateAggregation(array $source, string $path, array &$errors): void
    {
        $aggregation = $source['aggregation'] ?? null;
        if (! in_array($aggregation, self::AGGREGATIONS, true)) {
            $errors[] = "{$path}.aggregation: must be one of ".implode(', ', self::AGGREGATIONS).'.';

            return;
        }
        if ($aggregation !== 'count' && (! is_string($source['field'] ?? null) || $source['field'] === '')) {
            $errors[] = "{$path}.field: required for aggregation '{$aggregation}' (the numeric field to fold).";
        }
    }

    /** @param array<string, mixed> $source @param list<string> $errors */
    private function requireText(array $source, string $key, int $max, array &$errors, string $path = ''): void
    {
        $prefix = $path === '' ? $key : "{$path}.{$key}";
        $value = $source[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            $errors[] = "{$prefix}: required non-empty string.";

            return;
        }
        if (mb_strlen($value) > $max) {
            $errors[] = "{$prefix}: at most {$max} characters (got ".mb_strlen($value).'). Tighten the copy or split the slide.';
        }
    }

    /** @param array<string, mixed> $source @param list<string> $errors */
    private function optionalText(array $source, string $key, int $max, string $path, array &$errors): void
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            return;
        }
        if (! is_string($value)) {
            $errors[] = "{$path}.{$key}: must be a string when present.";

            return;
        }
        if (mb_strlen($value) > $max) {
            $errors[] = "{$path}.{$key}: at most {$max} characters (got ".mb_strlen($value).'). Tighten the copy or split the slide.';
        }
    }

    /** @param array<string, mixed> $source @param list<string> $errors */
    private function stringList(array $source, string $key, int $min, int $max, int $maxLen, string $path, array &$errors, bool $required): void
    {
        $value = $source[$key] ?? null;
        if ($value === null) {
            if ($required) {
                $errors[] = "{$path}.{$key}: required array of {$min} to {$max} strings.";
            }

            return;
        }
        if (! is_array($value) || count($value) < $min || count($value) > $max) {
            $errors[] = "{$path}.{$key}: {$min} to {$max} items.";

            return;
        }
        foreach (array_values($value) as $i => $item) {
            if (! is_string($item) || trim($item) === '') {
                $errors[] = "{$path}.{$key}.{$i}: must be a non-empty string.";
            } elseif (mb_strlen($item) > $maxLen) {
                $errors[] = "{$path}.{$key}.{$i}: at most {$maxLen} characters (got ".mb_strlen($item).'). Tighten the copy or split the slide.';
            }
        }
    }
}
