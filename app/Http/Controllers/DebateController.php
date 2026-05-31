<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Http\Requests\Debate\StoreDebateRequest;
use App\Http\Requests\Debate\UpdateDebateRequest;
use App\Jobs\StartDebateJob;
use App\Models\Debate;
use App\Services\AiProviderService;
use App\Services\Debate\DebatePresenter;
use App\Services\Debate\DebateTurnStreamer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DebateController extends Controller
{
    /** Distinct accent tokens cycled across participants for the UI. */
    private const ACCENTS = ['violet', 'emerald', 'amber', 'sky', 'rose', 'teal', 'fuchsia', 'orange', 'indigo'];

    public function __construct(private readonly AiProviderService $providers) {}

    public function index(Request $request): Response
    {
        return Inertia::render('debate/Index', [
            ...$this->sharedProps($request),
            'activeDebate' => null,
        ]);
    }

    public function show(Request $request, Debate $debate): Response
    {
        $this->authorizeDebate($request, $debate);

        return Inertia::render('debate/Index', [
            ...$this->sharedProps($request),
            'activeDebate' => DebatePresenter::debate(
                $debate->load(['participants', 'rounds.turns'])
            ),
        ]);
    }

    public function store(StoreDebateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $reachable = collect($this->providers->getReachableChatModels($user))->keyBy('value');
        $modelIds = collect($data['model_ids'])->unique()->values();

        $moderator = $data['moderator_model'] ?? null;
        if ($moderator === null || ! $reachable->has($moderator)) {
            $moderator = $reachable->keys()->first();
        }

        $debate = Debate::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => Visibility::Private,
            'title' => $data['title'] ?? null,
            'topic' => $data['topic'],
            'status' => 'pending',
            'max_rounds' => $data['max_rounds'] ?? 3,
            'moderator_model' => $moderator,
        ]);

        $modelIds->each(function (string $modelId, int $index) use ($debate, $reachable) {
            $entry = $reachable->get($modelId);
            $debate->participants()->create([
                'model' => $modelId,
                'provider' => $entry['provider'] ?? null,
                'display_name' => $entry['label'] ?? $modelId,
                'position' => $index,
                'accent' => self::ACCENTS[$index % count(self::ACCENTS)],
            ]);
        });

        StartDebateJob::dispatch($debate->id);

        return to_route('debates.show', $debate);
    }

    public function rename(UpdateDebateRequest $request, Debate $debate): RedirectResponse
    {
        $debate->update($request->validated());

        return back();
    }

    public function destroy(Request $request, Debate $debate): RedirectResponse
    {
        $this->authorizeDebate($request, $debate);

        $debate->delete();

        return to_route('debates.index');
    }

    /**
     * Cooperatively cancel an in-flight debate. Sets a cache flag the streaming
     * workers poll, and marks the debate stopped.
     */
    public function stop(Request $request, Debate $debate): JsonResponse
    {
        $this->authorizeDebate($request, $debate);

        Cache::put(DebateTurnStreamer::STOP_CACHE_PREFIX.$debate->id, true, now()->addMinutes(30));
        $debate->forceFill(['status' => 'stopped'])->save();

        return new JsonResponse(['stopped' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedProps(Request $request): array
    {
        $user = $request->user();

        $debates = Debate::query()
            ->forAccountContext($user)
            ->orderByRaw('last_activity_at IS NULL, last_activity_at DESC')
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'topic', 'status', 'last_activity_at', 'created_at'])
            ->map(fn (Debate $d) => [
                'id' => $d->id,
                'title' => $d->title ?? Str::limit($d->topic, 60),
                'status' => $d->status,
                'last_activity_at' => ($d->last_activity_at ?? $d->created_at)?->toIso8601String(),
            ]);

        $models = $this->providers->getReachableChatModels($user);

        return [
            'debates' => $debates,
            'models' => $models,
            'defaultModel' => $models[0]['value'] ?? null,
            'defaultModerator' => $models[0]['value'] ?? null,
        ];
    }

    private function authorizeDebate(Request $request, Debate $debate): void
    {
        if ($debate->user_id !== $request->user()->id) {
            throw new NotFoundHttpException('Debate not found.');
        }
    }
}
