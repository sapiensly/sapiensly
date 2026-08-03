<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\Record;
use App\Models\RecordEvent;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordTrail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A record's history, and the box for adding to it.
 *
 * Gated on being able to READ the record — through the same
 * {@see RecordQueryService::find} the page uses, so the access row_filter
 * applies and asking for a trail is never a way to learn that a record you may
 * not see exists. Commenting additionally needs UPDATE: a comment is a change
 * to the record's story, and somebody with a read-only grant is a reader.
 */
class AppRecordTrailController extends Controller
{
    /** Entries one page carries. A trail is read newest-first and rarely deep. */
    private const LIMIT = 50;

    public function __construct(
        private readonly AppManifestService $manifests,
        private readonly AppAccessResolver $accessResolver,
        private readonly RecordQueryService $records,
        private readonly RecordTrail $trail,
    ) {}

    public function index(Request $request, string $appSlug, string $recordId): JsonResponse
    {
        [$app, , $record] = $this->resolve($request, $appSlug, $recordId, 'read');

        $events = RecordEvent::query()
            ->where('app_id', $app->id)
            ->where('record_id', $record->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json([
            'events' => $events->map(fn (RecordEvent $e): array => [
                'id' => $e->id,
                'kind' => $e->kind,
                'actor' => $e->actor_name,
                'body' => $e->body,
                'changes' => $e->changes,
                'at' => $e->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request, string $appSlug, string $recordId): JsonResponse
    {
        [$app, , $record] = $this->resolve($request, $appSlug, $recordId, 'update');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $event = $this->trail->comment($app, $record, $data['body'], $request->user());

        if ($event === null) {
            throw new NotFoundHttpException('The comment could not be saved.');
        }

        return response()->json([
            'event' => [
                'id' => $event->id,
                'kind' => $event->kind,
                'actor' => $event->actor_name,
                'body' => $event->body,
                'changes' => null,
                'at' => $event->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * @return array{0: App, 1: array<string, mixed>, 2: Record}
     */
    private function resolve(Request $request, string $appSlug, string $recordId, string $action): array
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

        $context = [
            'current_user' => $user !== null ? ['id' => $user->id, 'email' => $user->email] : null,
            '__access' => $access,
            '__actor' => $user,
        ];

        // Which object it belongs to is not in the URL, so the record is found
        // by walking the app's objects. Each lookup carries the access filter,
        // so one the role may not see resolves to null exactly as it would on
        // the page — the trail cannot become a way to prove a record exists.
        foreach ($manifest['objects'] ?? [] as $object) {
            if (! $access->hasAccess || ! $access->can($object['id'], $action)) {
                continue;
            }

            $record = $this->records->actingAs($user)->find($app, $object['id'], $recordId, $manifest, $context);
            if ($record !== null) {
                return [$app, $manifest, $record];
            }
        }

        throw new NotFoundHttpException('No such record here.');
    }
}
