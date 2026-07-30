<?php

namespace App\Services\Import;

/**
 * What one spreadsheet column turned out to be: the manifest field type that
 * fits its values, plus the evidence behind that call so a human reviewing the
 * plan can disagree with it before any data is written.
 */
final class ColumnProfile
{
    /**
     * @param  string  $header  the column's name in the file
     * @param  string  $type  a manifest field type
     * @param  list<array{value: string, label: string}>|null  $options  for single_select / multi_select
     * @param  string|null  $listSeparator  the separator a multi_select column uses
     * @param  bool  $dayFirst  how an ambiguous d/m date was read
     * @param  list<string>  $samples  a few real values, for the preview
     * @param  list<string>  $notes  what a reviewer should know before committing
     */
    public function __construct(
        public readonly string $header,
        public readonly string $type,
        public readonly int $filled,
        public readonly int $distinct,
        public readonly ?array $options = null,
        public readonly ?string $listSeparator = null,
        public readonly bool $dayFirst = true,
        public readonly array $samples = [],
        public readonly array $notes = [],
    ) {}

    /**
     * The manifest field this column becomes when the import creates a new
     * object. Ids are minted by the caller, which owns id generation.
     *
     * @return array<string, mixed>
     */
    public function toField(string $id, string $slug): array
    {
        $field = [
            'id' => $id,
            'slug' => $slug,
            'name' => $this->header,
            'type' => $this->type,
        ];

        if ($this->options !== null) {
            $field['options'] = $this->options;
        }

        return $field;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'header' => $this->header,
            'type' => $this->type,
            'filled' => $this->filled,
            'distinct' => $this->distinct,
            'options' => $this->options,
            'list_separator' => $this->listSeparator,
            'day_first' => $this->dayFirst,
            'samples' => $this->samples,
            'notes' => $this->notes,
        ];
    }
}
