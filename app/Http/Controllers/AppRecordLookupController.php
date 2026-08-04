<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Which record carries this code.
 *
 * The other half of scanning: reading the barcode is the easy part, and the
 * thing somebody actually wants is the record it names — the pallet, the asset,
 * the order — open on their screen. Without this the scan produces a string
 * they then have to go and search for by hand, which is the job they were
 * trying not to do.
 *
 * Addressed by the FIELD, exactly like {@see AppRecordOptionsController}: the
 * caller holds a field id and nothing else, and cannot name an object of its
 * own choosing. What can be looked up is whatever that particular field is.
 *
 * Answers with at most ONE id and never with the record. A scan that matched
 * three rows is ambiguous, and this is not a search endpoint — a caller that
 * wanted to browse should be reading a table, which is access-filtered in the
 * same way but says so.
 */
class AppRecordLookupController extends Controller
{
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

        [$object, $field] = $this->locate($manifest, $fieldId);

        $previewRole = (string) $request->query('as_role', '');
        $access = $this->accessResolver->resolve($app, $manifest, $user, $previewRole !== '' ? $previewRole : null);

        if ($object === null || ! $access->hasAccess || ! $access->can($object['id'], 'read')) {
            throw new NotFoundHttpException('Nothing to look up here.');
        }

        $value = trim((string) $request->query('value', ''));
        if ($value === '') {
            return response()->json(['id' => null]);
        }

        $context = [
            'current_user' => $user !== null ? ['id' => $user->id, 'email' => $user->email] : null,
            '__access' => $access,
            '__actor' => $user,
        ];

        // Exact match, and through the query service like every other read: the
        // environment scope and the role's row_filter both apply, so a scan
        // cannot reach a demo pallet from production or somebody else's row
        // from a filtered view.
        $found = $this->records->actingAs($user)->query($app, [
            'object_id' => $object['id'],
            'filter' => ['op' => 'eq', 'field_id' => $field['id'], 'value' => $value],
            'limit' => 2,
        ], $manifest, $context);

        return response()->json([
            // Two matches is not an answer. Saying so beats opening whichever
            // one happened to sort first and letting somebody act on it.
            'id' => $found->count() === 1 ? $found->first()->id : null,
            'ambiguous' => $found->count() > 1,
        ]);
    }

    /**
     * The object a field belongs to, and the field itself.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function locate(array $manifest, string $fieldId): array
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            foreach ($object['fields'] ?? [] as $field) {
                if (($field['id'] ?? null) === $fieldId) {
                    return [$object, $field];
                }
            }
        }

        return [null, null];
    }
}
