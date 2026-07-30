<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\AppApiKey;
use App\Models\Record;
use App\Services\Apps\AppAccessContext;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordValidationException;
use App\Services\Records\RecordWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The REST data API for one app's records. The key identifies the app, so no
 * app appears in the URL — a credential cannot even ask about another app.
 *
 * Two gates on every call, and both must pass:
 *   1. the KEY's own scope (least privilege for this credential), and
 *   2. the ROLE's capabilities, resolved by the same AppAccessResolver the UI
 *      uses — including row filters and hidden fields.
 *
 * Reads go through RecordQueryService and writes through RecordWriteService, so
 * the API inherits every rule the runtime already enforces instead of restating
 * them. An API with its own shortcut into the table is how a database ends up
 * holding rows the app itself would have refused.
 */
class AppRecordApiController extends Controller
{
    /** Records returned in one page, and the ceiling a caller can ask for. */
    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    /** Comparisons a caller may ask for, mirroring the manifest's own grammar. */
    private const FILTER_OPS = [
        'eq', 'neq', 'gt', 'gte', 'lt', 'lte',
        'in', 'not_in', 'contains', 'starts_with', 'ends_with',
        'is_null', 'is_not_null',
    ];

    /** Implicit fields every object carries; addressable by their own name. */
    private const SYSTEM_FIELDS = ['id', 'sys_created_at', 'sys_updated_at'];

    public function __construct(
        private readonly RecordQueryService $records,
        private readonly RecordWriteService $writer,
    ) {}

    /**
     * What this key can see: the objects it may read and their fields, minus
     * anything its role hides. A discovery endpoint that listed everything would
     * teach a leaked key the shape of data it cannot read.
     */
    public function objects(Request $request): JsonResponse
    {
        [$app, $manifest, $access, $key] = $this->bound($request);

        $objects = [];
        foreach ($manifest['objects'] ?? [] as $object) {
            if (! $this->permits($key, $access, $object, 'read')) {
                continue;
            }

            $hidden = $access->hiddenFieldSlugs($object['id']);
            $objects[] = [
                'slug' => $object['slug'],
                'name' => $object['name'],
                'fields' => array_values(array_map(
                    fn (array $f): array => ['slug' => $f['slug'], 'name' => $f['name'], 'type' => $f['type']],
                    array_filter(
                        $object['fields'] ?? [],
                        fn (array $f): bool => ! in_array($f['slug'], $hidden, true),
                    ),
                )),
                'actions' => array_values(array_filter(
                    AppApiKey::ACTIONS,
                    fn (string $a): bool => $this->permits($key, $access, $object, $a),
                )),
            ];
        }

        return response()->json(['app' => $app->slug, 'objects' => $objects]);
    }

    public function index(Request $request, string $objectSlug): JsonResponse
    {
        [$app, $manifest, $access, $key] = $this->bound($request);
        $object = $this->object($manifest, $objectSlug);

        if ($object === null || ! $this->permits($key, $access, $object, 'read')) {
            return $this->forbidden();
        }

        $limit = min(
            max((int) $request->query('limit', (string) self::DEFAULT_LIMIT), 1),
            self::MAX_LIMIT,
        );

        $query = [
            'object_id' => $object['id'],
            'limit' => $limit,
            'offset' => max((int) $request->query('offset', '0'), 0),
        ];

        $filter = $this->filterFrom($request, $object, $access);
        if ($filter !== null) {
            $query['filter'] = $filter;
        }
        $sort = $this->sortFrom($request, $object, $access);
        if ($sort !== []) {
            $query['sort'] = $sort;
        }

        $result = $this->records->queryWithMeta(
            $app,
            $query,
            $manifest,
            $this->context($access),
        );

        return response()->json([
            'data' => $result['records']->map(fn (Record $r): array => $this->present($r, $access->hiddenFieldSlugs($object['id'])))->values(),
            'total' => $result['total'],
            'has_more' => $result['has_more'],
        ]);
    }

    public function show(Request $request, string $objectSlug, string $recordId): JsonResponse
    {
        [$app, $manifest, $access, $key] = $this->bound($request);
        $object = $this->object($manifest, $objectSlug);

        if ($object === null || ! $this->permits($key, $access, $object, 'read')) {
            return $this->forbidden();
        }

        // find() applies the role's row_filter, so a record outside the role's
        // rows is NOT FOUND rather than forbidden — the distinction would
        // confirm the record exists.
        $record = $this->records->find($app, $object['id'], $recordId, $manifest, $this->context($access));

        return $record === null
            ? $this->notFound()
            : response()->json(['data' => $this->present($record, $access->hiddenFieldSlugs($object['id']))]);
    }

    public function store(Request $request, string $objectSlug): JsonResponse
    {
        [$app, $manifest, $access, $key] = $this->bound($request);
        $object = $this->object($manifest, $objectSlug);

        if ($object === null || ! $this->permits($key, $access, $object, 'create')) {
            return $this->forbidden();
        }

        $values = $request->input('data');
        if (! is_array($values)) {
            return response()->json(['error' => 'invalid_body', 'message' => 'Send the record as a `data` object.'], 422);
        }

        try {
            $record = $this->writer->create($app, $manifest, $object['id'], $values, null);
        } catch (RecordValidationException $e) {
            return response()->json(['error' => 'validation_failed', 'fields' => $e->errors], 422);
        }

        return response()->json(['data' => $this->present($record, $access->hiddenFieldSlugs($object['id']))], 201);
    }

    public function update(Request $request, string $objectSlug, string $recordId): JsonResponse
    {
        [$app, $manifest, $access, $key] = $this->bound($request);
        $object = $this->object($manifest, $objectSlug);

        if ($object === null || ! $this->permits($key, $access, $object, 'update')) {
            return $this->forbidden();
        }

        $values = $request->input('data');
        if (! is_array($values)) {
            return response()->json(['error' => 'invalid_body', 'message' => 'Send the changed fields as a `data` object.'], 422);
        }

        $record = $this->records->find($app, $object['id'], $recordId, $manifest, $this->context($access));
        if ($record === null) {
            return $this->notFound();
        }

        // The writer re-checks read-only fields against the same context, so a
        // key cannot write a field its role may only read.
        $readonly = array_values(array_intersect(array_keys($values), $access->readonlyFieldSlugs($object['id'])));
        if ($readonly !== []) {
            return response()->json([
                'error' => 'forbidden_fields',
                'message' => 'These fields are read-only for this key: '.implode(', ', $readonly).'.',
            ], 403);
        }

        try {
            $updated = $this->writer->update($app, $manifest, $record, $values, null);
        } catch (RecordValidationException $e) {
            return response()->json(['error' => 'validation_failed', 'fields' => $e->errors], 422);
        }

        return response()->json(['data' => $this->present($updated, $access->hiddenFieldSlugs($object['id']))]);
    }

    public function destroy(Request $request, string $objectSlug, string $recordId): JsonResponse
    {
        [$app, $manifest, $access, $key] = $this->bound($request);
        $object = $this->object($manifest, $objectSlug);

        if ($object === null || ! $this->permits($key, $access, $object, 'delete')) {
            return $this->forbidden();
        }

        $record = $this->records->find($app, $object['id'], $recordId, $manifest, $this->context($access));
        if ($record === null) {
            return $this->notFound();
        }

        $this->writer->delete($record, $app, $manifest, null);

        return response()->json(['deleted' => $recordId]);
    }

    /**
     * Translate `?filter[slug]=value` / `?filter[slug][op]=value` into the
     * manifest's own filter grammar.
     *
     * Callers address fields by SLUG because that is what the discovery
     * endpoint gave them; the grammar addresses them by id. Anything that does
     * not resolve to a real, visible field is DROPPED rather than passed along:
     * a filter on a hidden field would otherwise let a caller probe values it is
     * not allowed to read, one comparison at a time.
     *
     * Whatever survives is ANDed under the role's own row_filter downstream, so
     * a filter can only ever narrow what the key could already see.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function filterFrom(Request $request, array $object, AppAccessContext $access): ?array
    {
        $raw = $request->query('filter');
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $byId = $this->visibleFields($object, $access);
        $conditions = [];

        foreach ($raw as $slug => $criterion) {
            $fieldId = $byId[$slug] ?? null;
            if ($fieldId === null) {
                continue;
            }

            if (! is_array($criterion)) {
                $conditions[] = ['op' => 'eq', 'field_id' => $fieldId, 'value' => $criterion];

                continue;
            }

            foreach ($criterion as $op => $value) {
                if (! in_array($op, self::FILTER_OPS, true)) {
                    continue;
                }
                $conditions[] = in_array($op, ['is_null', 'is_not_null'], true)
                    ? ['op' => $op, 'field_id' => $fieldId]
                    : ['op' => $op, 'field_id' => $fieldId, 'value' => $value];
            }
        }

        if ($conditions === []) {
            return null;
        }

        // Always a group, even for one condition: the grammar takes a single
        // object, and wrapping keeps the shape identical whatever arrived.
        return count($conditions) === 1
            ? $conditions[0]
            : ['op' => 'and', 'conditions' => $conditions];
    }

    /**
     * `?sort=slug` ascending, `?sort=-slug` descending, comma-separated for
     * several. Unknown or hidden fields are dropped, same reasoning as filters.
     *
     * @param  array<string, mixed>  $object
     * @return list<array{field_id: string, direction: string}>
     */
    private function sortFrom(Request $request, array $object, AppAccessContext $access): array
    {
        $raw = trim((string) $request->query('sort', ''));
        if ($raw === '') {
            return [];
        }

        $byId = $this->visibleFields($object, $access);
        $sort = [];

        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            $descending = str_starts_with($token, '-');
            $slug = ltrim($token, '-+');

            $fieldId = $byId[$slug] ?? null;
            if ($fieldId !== null) {
                $sort[] = ['field_id' => $fieldId, 'direction' => $descending ? 'desc' : 'asc'];
            }
        }

        return $sort;
    }

    /**
     * slug => field id, for the fields this key may actually see. The implicit
     * system fields are addressable too — "newest first" is the commonest thing
     * an integration asks for and there is no declared field for it.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, string>
     */
    private function visibleFields(array $object, AppAccessContext $access): array
    {
        $hidden = $access->hiddenFieldSlugs($object['id']);

        $map = [];
        foreach (self::SYSTEM_FIELDS as $slug) {
            $map[$slug] = $slug;
        }
        foreach ($object['fields'] ?? [] as $field) {
            if (! in_array($field['slug'], $hidden, true)) {
                $map[$field['slug']] = $field['id'];
            }
        }

        return $map;
    }

    /**
     * Both gates. The key's scope is checked first because it is the cheaper and
     * the narrower of the two; the role's capability is what actually knows
     * about policies.
     *
     * @param  array<string, mixed>  $object
     */
    private function permits(AppApiKey $key, AppAccessContext $access, array $object, string $action): bool
    {
        return $key->allows($object['slug'], $action)
            && $access->can($object['id'], $action);
    }

    /**
     * @return array{0: App, 1: array<string, mixed>, 2: AppAccessContext, 3: AppApiKey}
     */
    private function bound(Request $request): array
    {
        return [
            $request->attributes->get('apiApp'),
            $request->attributes->get('apiManifest'),
            $request->attributes->get('apiAccess'),
            $request->attributes->get('apiKey'),
        ];
    }

    /**
     * The read context. No `current_user`: a key is not a person, so an
     * expression reading {{current_user.id}} resolves to null and a row_filter
     * written against it matches nothing — the safe direction.
     *
     * @return array<string, mixed>
     */
    private function context(AppAccessContext $access): array
    {
        return ['__access' => $access, '__actor' => null, 'params' => []];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function object(array $manifest, string $slug): ?array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['slug'] ?? null) === $slug) {
                return $object;
            }
        }

        return null;
    }

    /**
     * Serialise a record for the wire, minus the fields the role hides.
     *
     * The strip happens HERE because RecordQueryService returns whole rows and
     * the runtime does its own stripping when it maps them into block data. An
     * API that skipped this step would be the one surface that leaks a
     * restricted field — and it would leak it to a credential, in JSON, at scale.
     *
     * @param  list<string>  $hidden  field slugs
     * @return array<string, mixed>
     */
    private function present(Record $record, array $hidden = []): array
    {
        $data = $record->data ?? [];
        foreach ($hidden as $slug) {
            unset($data[$slug]);
        }

        return [
            'id' => $record->id,
            'data' => $data,
            'created_at' => $record->created_at?->toIso8601String(),
            'updated_at' => $record->updated_at?->toIso8601String(),
        ];
    }

    private function forbidden(): JsonResponse
    {
        // One message for "no such object" and "not allowed": which objects
        // exist is itself something a scoped key should not learn.
        return response()->json([
            'error' => 'forbidden',
            'message' => 'This key cannot perform that action on that object.',
        ], 403);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['error' => 'not_found', 'message' => 'No such record.'], 404);
    }
}
