<?php

namespace App\Services\Import;

/**
 * What an import actually did. Rows that failed are reported with the line
 * number they occupy IN THE FILE — a user fixes a spreadsheet by scrolling to
 * row 418, not by being told "12 rows failed".
 */
final class ImportResult
{
    /** Failures listed individually before the rest are summarised. */
    private const MAX_REPORTED_ERRORS = 50;

    /**
     * @param  list<array{row: int, errors: array<string, list<string>>}>  $errors
     */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $failed,
        public readonly array $errors = [],
        public readonly bool $truncated = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'failed' => $this->failed,
            'truncated' => $this->truncated,
            'errors' => array_slice($this->errors, 0, self::MAX_REPORTED_ERRORS),
            'errors_omitted' => max(0, count($this->errors) - self::MAX_REPORTED_ERRORS),
        ];
    }

    /**
     * A one-line summary that never rounds a failure away — the caller reports
     * this verbatim, so a partial import can't read as a clean one.
     */
    public function summary(): string
    {
        $parts = [];
        if ($this->created > 0) {
            $parts[] = "{$this->created} created";
        }
        if ($this->updated > 0) {
            $parts[] = "{$this->updated} updated";
        }
        if ($parts === []) {
            $parts[] = 'nothing imported';
        }
        if ($this->failed > 0) {
            $parts[] = "{$this->failed} failed";
        }
        if ($this->truncated) {
            $parts[] = 'file truncated at '.SpreadsheetReader::MAX_ROWS.' rows';
        }

        return implode(', ', $parts).'.';
    }
}
