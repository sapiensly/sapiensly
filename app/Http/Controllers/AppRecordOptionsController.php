<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordLabel;
use App\Services\Records\RecordQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The records a relation field can point at, as {id, label} pairs.
 *
 * This is what makes a relation fillable. A relation stores an id, and until
 * now the form offered a plain text box for it: the app modelled the link,
 * generated the child list and the rollups from it, and then asked the person
 * to type an id nobody has. The only link anybody could actually make was by
 * creating the child from inside its parent, where the id comes from the URL.
 *
 * Two things keep it honest:
 *
 * - It is gated exactly like the table that shows the same records — the role
 *   must be able to READ the target object, and its row_filter narrows what
 *   comes back. A picker that listed rows the table hides would be the quietest
 *   way around a permission.
 * - It is NOT offered on a public portal. Listing every label of an object is
 *   an enumeration surface, and a read grant to a stranger is a grant to browse
 *   what they were shown, not to page through the whole book. Same call the
 *   export endpoint makes, for the same reason.
 */
class AppRecordOptionsController extends Controller
{
    /** Rows one request returns. Enough to scroll, small enough to stay fast. */
    private const LIMIT = 20;

    /** Ids a form may resolve in one go when it opens (a multi-select's chips). */
    private const MAX_IDS = 50;

    public function __construct(
        private readonly AppManifestService $manifests,
        private readonly AppAccessResolver $accessResolver,
        private readonly RecordQueryService $records,
    ) {}

    public function __invoke(Request $request, string $appSlug, string $fieldId): JsonResponse
    {
        $user = $request->user();

        $app = App::query()->forAccountContext($user)->where('slug', $appSlug)->first();
        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        $manifest = $this->manifests->getActiveManifest($app);
        if ($manifest === null) {
            throw new NotFoundHttpException("App '{$appSlug}' has no published manifest.");
        }

        $previewRole = (string) $request->query('as_role', '');
        $access = $this->accessResolver->resolve($app, $manifest, $user, $previewRole !== '' ? $previewRole : null);

        // Addressed by the FIELD, not by the object it happens to point at. The
        // form holds a field id and nothing else — it would have to be handed
        // the target's slug to ask any other way — and this keeps the caller
        // from naming an object of its own choosing: what comes back is
        // whatever this particular relation is allowed to point at.
        $object = $this->targetOf($manifest, $fieldId);

        if ($object === null || ! $access->hasAccess || ! $access->can($object['id'], 'read')) {
            throw new NotFoundHttpException('Nothing to choose from here.');
        }

        $context = [
            'current_user' => $user !== null ? ['id' => $user->id, 'email' => $user->email] : null,
            'params' => array_filter($request->query(), is_string(...)),
            '__access' => $access,
            '__actor' => $user,
        ];

        // Asking to resolve ids and asking for a list are different questions,
        // and an `ids` that survives filtering as empty is the first, answered
        // "none" — not the second. Conflated, a malformed `ids` fell through to
        // listing the whole object, which is the opposite of what was asked.
        $wantsIds = is_string($request->query('ids')) && trim((string) $request->query('ids')) !== '';
        $ids = $this->requestedIds($request);
        $records = $this->records->actingAs($user);

        if ($wantsIds) {
            // Resolving ids is what an EDIT form does on open: it holds the
            // stored id and needs the name to show in the box. Answered here
            // rather than by a second endpoint because it is the same question
            // — which record is this — asked about a known id.
            $records = $records->findMany($app, $object['id'], $ids, $manifest, $context);
        } else {
            $query = [
                'object_id' => $object['id'],
                'limit' => self::LIMIT,
                // Newest first, so an empty box opens on what somebody just
                // added — which is what they are most likely reaching for.
                'sort' => [['field_id' => 'sys_created_at', 'direction' => 'desc']],
            ];

            $search = trim((string) $request->query('q', ''));
            if ($search !== '') {
                $query['search'] = $search;
            }

            $records = $records->query($app, $query, $manifest, $context);
        }

        $options = $records
            ->map(fn ($record): array => [
                'id' => (string) $record->id,
                'label' => RecordLabel::of($object, (array) ($record->data ?? []), (string) $record->id),
            ])
            ->values()
            ->all();

        return response()->json([
            'options' => $options,
            // The list is capped, so the box has to be able to say "keep
            // typing" rather than implying these are all of them.
            'truncated' => ! $wantsIds && count($options) >= self::LIMIT,
        ]);
    }

    /**
     * The object a relation field points at, or null when the id names no
     * relation on this app.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function targetOf(array $manifest, string $fieldId): ?array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                if (($field['id'] ?? null) !== $fieldId) {
                    continue;
                }
                if (($field['type'] ?? null) !== 'relation') {
                    return null;
                }

                foreach ($manifest['objects'] ?? [] as $candidate) {
                    if (($candidate['id'] ?? null) === ($field['target_object_id'] ?? null)) {
                        return $candidate;
                    }
                }

                return null;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function requestedIds(Request $request): array
    {
        $raw = $request->query('ids');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $ids = array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            fn (string $id): bool => $id !== '' && preg_match('/^rec_[a-z0-9]+$/', $id) === 1,
        ));

        return array_slice($ids, 0, self::MAX_IDS);
    }
}
