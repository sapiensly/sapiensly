<?php

namespace App\Services\Import;

/**
 * One spreadsheet column bound to one object field — or deliberately bound to
 * nothing. A skipped column is represented explicitly rather than dropped, so
 * the plan a reviewer reads lists every column in the file and says what
 * happens to each; a column that simply vanished from the preview is how an
 * import quietly loses data.
 */
final class ColumnMapping
{
    public function __construct(
        public readonly string $header,
        public readonly ?string $fieldSlug,
        public readonly ?string $fieldId,
        public readonly ?string $type,
        public readonly ColumnProfile $profile,
        /** Why this column is skipped, when it is. */
        public readonly ?string $skipReason = null,
    ) {}

    public static function skipped(ColumnProfile $profile, string $reason): self
    {
        return new self($profile->header, null, null, null, $profile, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'header' => $this->header,
            'field_slug' => $this->fieldSlug,
            'field_id' => $this->fieldId,
            'type' => $this->type,
            'skip_reason' => $this->skipReason,
            'profile' => $this->profile->toArray(),
        ];
    }
}
