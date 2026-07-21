<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\AppFile;
use App\Models\Record;
use App\Models\User;
use App\Services\Workflows\WorkflowTriggerDispatcher;
use InvalidArgumentException;
use RuntimeException;

/**
 * Validates and persists Record CRUD operations using the App's manifest as
 * the source of truth for field types, constraints, and permissions.
 *
 * On validation failure throws RecordValidationException with errors keyed by
 * field_slug — callers translate that to HTTP 422 / form errors.
 */
class RecordWriteService
{
    /**
     * Resolved lazily via the container to break the circular dependency
     * RecordWriteService ↔ WorkflowEngine ↔ WorkflowTriggerDispatcher.
     */
    private ?WorkflowTriggerDispatcher $triggersCache = null;

    public function __construct() {}

    private function triggers(): WorkflowTriggerDispatcher
    {
        return $this->triggersCache ??= app(WorkflowTriggerDispatcher::class);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $values  raw values keyed by field_slug
     */
    public function create(App $app, array $manifest, string $objectId, array $values, ?User $user = null): Record
    {
        $object = $this->findObject($manifest, $objectId);
        $values = $this->normalizeKeys($object, $values);
        $clean = $this->validate($object, $values, mode: 'create', app: $app, manifest: $manifest);

        $record = Record::create([
            'organization_id' => $app->organization_id,
            'app_id' => $app->id,
            'object_definition_id' => $objectId,
            'data' => $clean,
            'created_by_user_id' => $user?->id,
            'updated_by_user_id' => $user?->id,
        ]);

        $this->triggers()->dispatch($app, $manifest, 'record.created', [
            'record' => $this->recordPayload($record),
        ], $user);

        return $record;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $values  partial update — only keys present are validated/written
     */
    public function update(App $app, array $manifest, Record $record, array $values, ?User $user = null): Record
    {
        if ($record->app_id !== $app->id) {
            throw new InvalidArgumentException('Record does not belong to this App.');
        }

        $object = $this->findObject($manifest, $record->object_definition_id);
        $values = $this->normalizeKeys($object, $values);
        $clean = $this->validate($object, $values, mode: 'update', app: $app, manifest: $manifest);

        $before = $record->data ?? [];
        $merged = array_merge($before, $clean);
        $record->update([
            'data' => $merged,
            'updated_by_user_id' => $user?->id,
        ]);
        $updated = $record->refresh();

        $this->triggers()->dispatch($app, $manifest, 'record.updated', [
            'record' => $this->recordPayload($updated),
            'before' => $before,
            'changed' => array_keys($clean),
        ], $user);

        return $updated;
    }

    public function delete(Record $record, ?App $app = null, ?array $manifest = null, ?User $user = null): void
    {
        $snapshot = $this->recordPayload($record);
        $record->delete();

        if ($app !== null && $manifest !== null) {
            $this->triggers()->dispatch($app, $manifest, 'record.deleted', [
                'record' => $snapshot,
            ], $user);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPayload(Record $record): array
    {
        return [
            'id' => $record->id,
            'app_id' => $record->app_id,
            'object_definition_id' => $record->object_definition_id,
            'data' => $record->data,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Accept values keyed by EITHER a field slug or a field id, and return them
     * keyed by slug (the form the rest of the service expects). Callers that
     * learned field ids from the manifest (e.g. the MCP create/update tools) can
     * pass ids directly; slugs and unknown keys pass through untouched so the
     * "unknown field" validation still fires.
     *
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeKeys(array $object, array $values): array
    {
        $idToSlug = [];
        foreach ($object['fields'] as $field) {
            $idToSlug[$field['id']] = $field['slug'];
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[$idToSlug[$key] ?? $key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed> cleaned values ready to write to JSONB
     */
    private function validate(array $object, array $values, string $mode, App $app, array $manifest): array
    {
        $errors = [];
        $clean = [];
        // Per-target index of existing records, so relation fields resolve to a
        // real record id (and reject a value that matches none) without querying
        // the same target twice in one write. Keyed by target object id.
        $relCache = [];

        foreach ($object['fields'] as $field) {
            $slug = $field['slug'];
            $type = $field['type'];
            $isPresent = array_key_exists($slug, $values);
            $raw = $values[$slug] ?? null;

            // On update, only validate fields the caller is actually sending.
            if ($mode === 'update' && ! $isPresent) {
                continue;
            }

            // required
            if (! empty($field['required']) && ($raw === null || $raw === '')) {
                $errors[$slug][] = "{$field['name']} is required.";

                continue;
            }

            if ($raw === null || $raw === '') {
                $clean[$slug] = null;

                continue;
            }

            $fieldErrors = [];
            $clean[$slug] = $this->castAndValidate($field, $type, $raw, $fieldErrors, $app, $manifest, $relCache);
            if ($fieldErrors !== []) {
                $errors[$slug] = array_merge($errors[$slug] ?? [], $fieldErrors);
            }
        }

        // Reject keys the manifest doesn't know about — surface a clear error
        // instead of silently dropping data the user thought was being saved.
        $knownSlugs = array_column($object['fields'], 'slug');
        foreach (array_keys($values) as $sentSlug) {
            if (! in_array($sentSlug, $knownSlugs, true)) {
                $errors[$sentSlug][] = "Unknown field '{$sentSlug}' for object '{$object['slug']}'.";
            }
        }

        if ($errors !== []) {
            throw new RecordValidationException($errors);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array{ids: array<string, true>, byValue: array<string, string>}>  $relCache
     */
    private function castAndValidate(array $field, string $type, mixed $raw, array &$errors, App $app, array $manifest, array &$relCache): mixed
    {
        return match ($type) {
            'string' => $this->validateString($field, $raw, $errors),
            'email' => $this->validateContact($field, $raw, $errors, 'email'),
            'url' => $this->validateContact($field, $raw, $errors, 'url'),
            'phone' => $this->validateContact($field, $raw, $errors, 'phone'),
            'long_text' => $this->validateLongText($field, $raw, $errors),
            'number' => $this->validateNumber($field, $raw, $errors),
            'currency' => $this->validateNumber($field, $raw, $errors),
            'boolean' => (bool) $raw,
            'date' => $this->validateDate($field, $raw, $errors, datetime: false),
            'datetime' => $this->validateDate($field, $raw, $errors, datetime: true),
            'single_select' => $this->validateSelect($field, $raw, $errors, multi: false),
            'multi_select' => $this->validateSelect($field, $raw, $errors, multi: true),
            'relation' => $this->validateRelation($field, $raw, $errors, $app, $manifest, $relCache),
            'rating' => $this->validateRating($field, $raw, $errors),
            'slider' => $this->validateSlider($field, $raw, $errors),
            'date_range' => $this->validateDateRange($field, $raw, $errors),
            'file' => $this->validateFile($field, $raw, $errors),
            'rich_text' => $this->validateRichText($field, $raw, $errors),
            default => $raw,
        };
    }

    /**
     * Rich-text values are HTML produced by TipTap. We sanitise server-side
     * because client-side sanitisation can be bypassed, then enforce
     * max_length on the plain-text content (not the markup) so a user can
     * still bold/italicise heavily without tripping the limit.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateRichText(array $field, mixed $raw, array &$errors): string
    {
        if (! is_string($raw)) {
            $errors[] = "{$field['name']} must be a string of HTML.";

            return '';
        }
        $sanitizer = app(HtmlSanitizer::class);
        $clean = $sanitizer->sanitize($raw);

        if (isset($field['max_length'])) {
            $plain = $sanitizer->plainText($clean);
            if (mb_strlen($plain) > $field['max_length']) {
                $errors[] = "{$field['name']} must be at most {$field['max_length']} characters of text.";
            }
        }

        return $clean;
    }

    /**
     * File values arrive from the frontend as {file_id, url, original_name,
     * mime, size_bytes} — the payload returned by the upload endpoint. We
     * trust file_id as the source of truth and re-derive the other fields
     * from the AppFile row to defend against clients lying about size/mime
     * to bypass per-field limits.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     * @return array{file_id: string, original_name: string, mime: string, size_bytes: int, url: string}|null
     */
    private function validateFile(array $field, mixed $raw, array &$errors): ?array
    {
        if (! is_array($raw) || ! isset($raw['file_id'])) {
            $errors[] = "{$field['name']} must be an uploaded file payload (got: ".gettype($raw).').';

            return null;
        }

        $fileId = (string) $raw['file_id'];
        $file = AppFile::query()->find($fileId);
        if ($file === null) {
            $errors[] = "{$field['name']}: uploaded file '{$fileId}' was not found.";

            return null;
        }

        $maxBytes = (int) (($field['max_size_mb'] ?? 10) * 1024 * 1024);
        if ($file->size_bytes > $maxBytes) {
            $errors[] = "{$field['name']} exceeds the max size of {$field['max_size_mb']}MB.";
        }

        if (isset($field['mime_types']) && is_array($field['mime_types']) && $field['mime_types'] !== []) {
            if (! $this->mimeMatches($file->mime, $field['mime_types'])) {
                $allowed = implode(', ', $field['mime_types']);
                $errors[] = "{$field['name']}: MIME type '{$file->mime}' is not allowed (must be one of: {$allowed}).";
            }
        }

        return [
            'file_id' => $file->id,
            'original_name' => $file->original_name,
            'mime' => $file->mime,
            'size_bytes' => (int) $file->size_bytes,
            // Stored as a relative route fragment — the runtime URL is composed
            // at read time via route('apps.runtime.files', ...) instead of
            // pinning today's host into JSONB forever.
            'url' => $raw['url'] ?? '',
        ];
    }

    /**
     * Check a MIME string against a list of allowed patterns. Supports
     * `image/*` style wildcards on the subtype.
     *
     * @param  list<string>  $patterns
     */
    private function mimeMatches(string $mime, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $mime) {
                return true;
            }
            if (str_ends_with($pattern, '/*')) {
                $prefix = substr($pattern, 0, -1); // keep "image/"
                if (str_starts_with($mime, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateRating(array $field, mixed $raw, array &$errors): int
    {
        if (! is_numeric($raw)) {
            $errors[] = "{$field['name']} must be a number.";

            return 0;
        }
        $value = (int) round((float) $raw);
        $max = (int) ($field['max'] ?? 5);
        if ($value < 0 || $value > $max) {
            $errors[] = "{$field['name']} must be between 0 and {$max}.";
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateSlider(array $field, mixed $raw, array &$errors): float
    {
        if (! is_numeric($raw)) {
            $errors[] = "{$field['name']} must be a number.";

            return 0.0;
        }
        $value = (float) $raw;
        $min = (float) ($field['min'] ?? 0);
        $max = (float) ($field['max'] ?? 100);
        if ($value < $min || $value > $max) {
            $errors[] = "{$field['name']} must be between {$min} and {$max}.";
        }

        return $value;
    }

    /**
     * Date range values are stored as `{from, to}` JSONB. Both endpoints are
     * required, must parse as dates (or ISO datetimes when include_time is on),
     * and `from` must be <= `to`.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     * @return array{from: string, to: string}|null
     */
    private function validateDateRange(array $field, mixed $raw, array &$errors): ?array
    {
        if (! is_array($raw) || ! isset($raw['from'], $raw['to'])) {
            $errors[] = "{$field['name']} must be an object with `from` and `to` strings.";

            return null;
        }
        $from = trim((string) $raw['from']);
        $to = trim((string) $raw['to']);
        if ($from === '' || $to === '') {
            $errors[] = "{$field['name']} requires both `from` and `to`.";

            return null;
        }
        if (strtotime($from) === false || strtotime($to) === false) {
            $errors[] = "{$field['name']} contains an unparseable date.";

            return null;
        }
        if (strcmp($from, $to) > 0) {
            $errors[] = "{$field['name']}: `from` must be on or before `to`.";
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateString(array $field, mixed $raw, array &$errors): string
    {
        $value = (string) $raw;
        if (isset($field['max_length']) && mb_strlen($value) > $field['max_length']) {
            $errors[] = "{$field['name']} must be at most {$field['max_length']} characters.";
        }
        if (isset($field['min_length']) && mb_strlen($value) < $field['min_length']) {
            $errors[] = "{$field['name']} must be at least {$field['min_length']} characters.";
        }
        if (isset($field['pattern']) && @preg_match("/{$field['pattern']}/u", $value) === 0) {
            $errors[] = "{$field['name']} does not match the required pattern.";
        }

        return $value;
    }

    /**
     * The contact trio (email / url / phone): string semantics plus a format
     * check when a non-empty value arrives (empty passes through — `required`
     * is enforced upstream like every other type).
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateContact(array $field, mixed $raw, array &$errors, string $kind): string
    {
        $value = trim((string) $raw);
        if (isset($field['max_length']) && mb_strlen($value) > $field['max_length']) {
            $errors[] = "{$field['name']} must be at most {$field['max_length']} characters.";
        }
        if ($value === '') {
            return $value;
        }

        $ok = match ($kind) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true),
            'phone' => preg_match('/^\+?[0-9()\/\.\-\s]{5,30}$/', $value) === 1,
        };
        if (! $ok) {
            $errors[] = match ($kind) {
                'email' => "{$field['name']} must be a valid email address.",
                'url' => "{$field['name']} must be a valid http(s) URL.",
                'phone' => "{$field['name']} must be a valid phone number.",
            };
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateLongText(array $field, mixed $raw, array &$errors): string
    {
        $value = (string) $raw;
        if (isset($field['max_length']) && mb_strlen($value) > $field['max_length']) {
            $errors[] = "{$field['name']} must be at most {$field['max_length']} characters.";
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateNumber(array $field, mixed $raw, array &$errors): float|int
    {
        if (! is_numeric($raw)) {
            $errors[] = "{$field['name']} must be a number.";

            return 0;
        }
        $value = $raw + 0;
        if (isset($field['min']) && $value < $field['min']) {
            $errors[] = "{$field['name']} must be at least {$field['min']}.";
        }
        if (isset($field['max']) && $value > $field['max']) {
            $errors[] = "{$field['name']} must be at most {$field['max']}.";
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateDate(array $field, mixed $raw, array &$errors, bool $datetime): ?string
    {
        $value = (string) $raw;
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            $errors[] = $datetime
                ? "{$field['name']} must be a valid ISO datetime."
                : "{$field['name']} must be a valid ISO date.";

            return null;
        }

        // Normalise to ISO so the JSONB blob is consistent regardless of input
        // format. Date stays as YYYY-MM-DD, datetime as ISO 8601 UTC.
        return $datetime
            ? gmdate('Y-m-d\TH:i:s\Z', $timestamp)
            : gmdate('Y-m-d', $timestamp);
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     */
    private function validateSelect(array $field, mixed $raw, array &$errors, bool $multi): mixed
    {
        $allowed = array_column($field['options'] ?? [], 'value');

        if ($multi) {
            if (! is_array($raw)) {
                $errors[] = "{$field['name']} must be an array of values.";

                return [];
            }
            $bad = array_diff($raw, $allowed);
            if ($bad !== []) {
                $errors[] = "{$field['name']} contains values that aren't allowed: ".implode(', ', $bad).'.';
            }

            return array_values(array_intersect($raw, $allowed));
        }

        $value = (string) $raw;
        if (! in_array($value, $allowed, true)) {
            $errors[] = "{$field['name']} must be one of: ".implode(', ', $allowed).'.';

            return null;
        }

        return $value;
    }

    /**
     * Resolve a relation field's value to a REAL target-record id, so a bad
     * reference never persists as a dangling foreign key. Each reference is kept
     * only when it is an existing record id of the target object, or a value that
     * uniquely identifies one (its name/title — any of the target's string
     * fields, case-insensitively). Anything else — the target OBJECT id, a
     * hallucinated record id, an unmatched name — is a hard error, surfaced to the
     * caller instead of silently stored. This closes the seeding bug where a demo
     * row's `owner` held the team-members OBJECT id and rollups/related lists came
     * up empty.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $manifest
     * @param  array<string, array{ids: array<string, true>, byValue: array<string, string>}>  $relCache
     * @return string|list<string>|null
     */
    private function validateRelation(array $field, mixed $raw, array &$errors, App $app, array $manifest, array &$relCache): string|array|null
    {
        $multi = ($field['cardinality'] ?? null) === 'many_to_many';
        $target = isset($field['target_object_id'])
            ? $this->findObjectOrNull($manifest, (string) $field['target_object_id'])
            : null;

        // A malformed relation (no resolvable target) is the manifest validator's
        // job to flag — keep the raw ref rather than dropping the caller's data.
        if ($target === null) {
            return is_array($raw) ? array_values($raw) : (string) $raw;
        }

        $refs = is_array($raw) ? array_values($raw) : [$raw];
        $resolved = [];
        foreach ($refs as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            $id = $this->resolveRelationRef($ref, $target, $app, $relCache);
            if ($id === null) {
                $errors[] = "No {$target['name']} record matches '{$ref}' — pass an existing record id, or a value identifying one (e.g. its name).";

                continue;
            }
            $resolved[] = $id;
        }

        return $multi ? array_values(array_unique($resolved)) : ($resolved[0] ?? null);
    }

    /**
     * A reference resolves to a target-record id when it IS one, else when it
     * matches one target record's name/title value. Null when nothing matches.
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, array{ids: array<string, true>, byValue: array<string, string>}>  $relCache
     */
    private function resolveRelationRef(string $ref, array $target, App $app, array &$relCache): ?string
    {
        $index = $this->relationIndex($app, $target, $relCache);

        if (isset($index['ids'][$ref])) {
            return $ref;
        }

        return $index['byValue'][mb_strtolower($ref)] ?? null;
    }

    /**
     * Build (once per target, per write) an index of the target object's existing
     * records: the set of record ids, and a map from each record's string-field
     * values (lower-cased) to its id — so a relation can be given by name.
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, array{ids: array<string, true>, byValue: array<string, string>}>  $relCache
     * @return array{ids: array<string, true>, byValue: array<string, string>}
     */
    private function relationIndex(App $app, array $target, array &$relCache): array
    {
        $targetId = (string) $target['id'];
        if (isset($relCache[$targetId])) {
            return $relCache[$targetId];
        }

        $stringSlugs = array_values(array_map(
            fn (array $f): string => (string) $f['slug'],
            array_filter(
                $target['fields'] ?? [],
                fn ($f): bool => is_array($f) && in_array($f['type'] ?? '', ['string', 'long_text'], true),
            ),
        ));

        $ids = [];
        $byValue = [];
        Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $targetId)
            ->orderBy('created_at')
            ->get(['id', 'data'])
            ->each(function (Record $record) use (&$ids, &$byValue, $stringSlugs): void {
                $ids[$record->id] = true;
                $data = is_array($record->data) ? $record->data : [];
                foreach ($stringSlugs as $slug) {
                    $value = $data[$slug] ?? null;
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        // First record wins when two share a label — deterministic.
                        $byValue[mb_strtolower(trim((string) $value))] ??= $record->id;
                    }
                }
            });

        return $relCache[$targetId] = ['ids' => $ids, 'byValue' => $byValue];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findObjectOrNull(array $manifest, string $objectId): ?array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if (is_array($object) && ($object['id'] ?? null) === $objectId) {
                return $object;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function findObject(array $manifest, string $objectId): array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if ($object['id'] === $objectId) {
                return $object;
            }
        }
        throw new RuntimeException("Object '{$objectId}' not found in manifest.");
    }
}
