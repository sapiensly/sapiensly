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
     * Not a performance number — it is how much somebody can undo. There is no
     * undo for records, so the ceiling is "a mistake you could still fix by
     * hand in an afternoon".
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
            'action' => ['required', Rule::in(['delete', 'set'])],
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

        $needs = $data['action'] === 'delete' ? 'delete' : 'update';
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

        $context = [
            'current_user' => $user !== null ? ['id' => $user->id, 'email' => $user->email] : null,
            '__access' => $access,
            '__actor' => $user,
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

            if ($data['action'] === 'delete') {
                $this->writes->delete($record, $app, $manifest, $user);
            } else {
                $this->writes->update($app, $manifest, $record, [$field['slug'] => $data['value']], $user);
            }

            $done++;
        }

        // Both numbers, always. "12 changed" when 3 were silently skipped is
        // the kind of report somebody acts on.
        return response()->json(['changed' => $done, 'skipped' => $skipped]);
    }
}
