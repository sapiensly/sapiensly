<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The same edit, applied to the rows somebody picked.
 *
 * Every record still goes through {@see RecordWriteService}, one at a time.
 * That is deliberate and it is the whole design: a bulk UPDATE straight to the
 * database would be faster and would skip validation, the access row_filter,
 * the workflow triggers and the activity trail — so "I marked twelve as
 * delivered" would fire no automation and leave no history, while doing it
 * twelve times by hand would do both. A feature whose behaviour depends on how
 * many rows you selected is a feature nobody can reason about.
 *
 * The price is a bounded loop, so the batch is bounded too.
 */
class AppBulkActionController extends Controller
{
    /**
     * Rows one call may touch.
     *
     * Not a performance number. It was once the only thing standing between a
     * misclick and permanent loss; now that a delete goes to the trash and
     * comes back, it is just a bound on how much work one request does — kept
     * because the loop is a loop, and because `purge` still means it.
     */
    private const MAX_ROWS = 200;

    public function __construct(
        private readonly AppManifestService $manifests,
        private readonly AppAccessResolver $accessResolver,
        private readonly RecordQueryService $records,
        private readonly RecordWriteService $writes,
    ) {}

    public function __invoke(Request $request, string $appSlug): JsonResponse
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

        $data = $request->validate([
            'object_id' => ['required', 'string'],
            'action' => ['required', Rule::in(['delete', 'set', 'restore', 'purge'])],
            'record_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'record_ids.*' => ['string', 'regex:/^rec_[a-z0-9]+$/'],
            'field_id' => ['required_if:action,set', 'string'],
            'value' => ['nullable'],
        ]);

        $object = collect($manifest['objects'] ?? [])->firstWhere('id', $data['object_id']);
        if ($object === null) {
            throw new NotFoundHttpException('No such object here.');
        }

        $previewRole = (string) $request->query('as_role', '');
        $access = $this->accessResolver->resolve($app, $manifest, $user, $previewRole !== '' ? $previewRole : null);

        // Restoring and emptying are both the delete permission: whoever may
        // send a record to the trash is who may take it back out or finish the
        // job. Splitting them would invent a role that can destroy but not undo.
        $needs = $data['action'] === 'set' ? 'update' : 'delete';
        abort_unless($access->hasAccess && $access->can($object['id'], $needs), 403);

        // A `set` may only touch a field that is really on the object, and
        // never one the app works out for itself.
        $field = null;
        if ($data['action'] === 'set') {
            $field = collect($object['fields'] ?? [])->firstWhere('id', $data['field_id']);
            if ($field === null || in_array($field['type'] ?? '', ['rollup', 'lookup', 'formula'], true)) {
                throw new NotFoundHttpException('That field cannot be set.');
            }
        }

        // Restoring and emptying READ from the trash; the other two read the
        // live rows. Either way the lookup goes through the same scoped find,
        // so the environment and the role's row filter still decide what is
        // reachable — a trash view is not a way around them.
        $readsTrash = in_array($data['action'], ['restore', 'purge'], true);

        $context = [
            'current_user' => $user !== null ? ['id' => $user->id, 'email' => $user->email] : null,
            '__access' => $access,
            '__actor' => $user,
            '__trashed' => $readsTrash,
        ];

        $done = 0;
        $skipped = 0;

        foreach (array_unique($data['record_ids']) as $recordId) {
            // Re-read each one under the access filter. The ids came from a
            // browser, and a row the role may not see must not become
            // reachable by being named in a list.
            $record = $this->records->actingAs($user)->find($app, $object['id'], $recordId, $manifest, $context);

            if ($record === null) {
                $skipped++;

                continue;
            }

            match ($data['action']) {
                'delete' => $this->writes->delete($record, $app, $manifest, $user),
                'restore' => $this->writes->restore($record, $app, $user),
                'purge' => $this->writes->purge($record, $app, $user),
                default => $this->writes->update($app, $manifest, $record, [$field['slug'] => $data['value']], $user),
            };

            $done++;
        }

        // Both numbers, always. "12 changed" when 3 were silently skipped is
        // the kind of report somebody acts on.
        return response()->json(['changed' => $done, 'skipped' => $skipped]);
    }
}
