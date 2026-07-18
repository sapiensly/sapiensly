<?php

namespace App\Http\Controllers;

use App\Models\AiCatalogModel;
use App\Services\Ai\PlaygroundRunner;
use App\Services\AiProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Playground — an ad-hoc tester for every specialized AI capability. Each run
 * uses the capability's configured admin default (or a user-chosen override) and
 * is executed by {@see PlaygroundRunner}. Nothing is persisted; binary outputs
 * (images, speech) come back inline as base64 data URLs.
 */
class PlaygroundController extends Controller
{
    /** Catalog capabilities a user may override-pick across the playground. */
    private const PICKER_CAPABILITIES = ['chat', 'embeddings', 'vision', 'image', 'transcription', 'speech', 'rerank'];

    public function __construct(
        private readonly PlaygroundRunner $runner,
        private readonly AiProviderService $providers,
    ) {}

    public function index(): Response
    {
        $capabilities = [];
        foreach (PlaygroundRunner::CAPABILITIES as $key => $meta) {
            $model = null;
            try {
                $model = $this->runner->resolveForRun($key, null)['model'];
            } catch (\Throwable) {
                // Unconfigured — the UI shows a "set a default" hint.
            }

            $capabilities[] = [
                'key' => $key,
                'pickerCapability' => $meta['catalog'],
                'input' => $meta['input'],
                'output' => $meta['output'],
                'defaultModel' => $model,
            ];
        }

        return Inertia::render('playground/Index', [
            'capabilities' => $capabilities,
            'modelsByCapability' => $this->modelsByCapability(),
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capability' => ['required', 'string', Rule::in(array_keys(PlaygroundRunner::CAPABILITIES))],
            'model_id' => ['nullable', Rule::exists('ai_catalog_models', 'id')],
            'prompt' => ['nullable', 'string', 'max:2000'],
            'text' => ['nullable', 'string', 'max:2000'],
            'query' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:100'],
            'documents.*' => ['string', 'max:10000'],
            'file' => ['nullable', 'file', 'max:30720', 'mimes:jpg,jpeg,png,gif,webp,pdf,mp3,wav,m4a,ogg,webm,flac'],
            // Speech generation voice controls.
            'voice' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        // Resolve the tenant's runtime AI keys (used by the SDK and OpenRouter).
        $this->providers->applyRuntimeConfig($user);

        $startedAt = hrtime(true);

        try {
            $result = $this->runner->execute(
                $user,
                $validated['capability'],
                $validated['model_id'] ?? null,
                [
                    'prompt' => $validated['prompt'] ?? null,
                    'text' => $validated['text'] ?? null,
                    'query' => $validated['query'] ?? null,
                    'documents' => $validated['documents'] ?? [],
                    'voice' => $validated['voice'] ?? null,
                    'gender' => $validated['gender'] ?? null,
                    'instructions' => $validated['instructions'] ?? null,
                ],
                $request->file('file'),
            );

            $result['duration_ms'] = (int) round((hrtime(true) - $startedAt) / 1_000_000);

            return response()->json(['ok' => true] + $result);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            ], 422);
        }
    }

    /**
     * Enabled catalog models grouped by the capabilities the playground can pick.
     *
     * @return array<string, array<int, array{id: string, driver: string, name: string, label: string}>>
     */
    private function modelsByCapability(): array
    {
        $rows = AiCatalogModel::query()
            ->whereIn('capability', self::PICKER_CAPABILITIES)
            ->where('is_enabled', true)
            ->orderBy('driver')
            ->orderBy('label')
            ->get(['id', 'driver', 'model_id', 'label', 'capability']);

        $out = array_fill_keys(self::PICKER_CAPABILITIES, []);
        foreach ($rows as $row) {
            $out[$row->capability][] = [
                'id' => (string) $row->id,
                'driver' => $row->driver,
                'name' => $row->model_id,
                'label' => $row->label,
            ];
        }

        return $out;
    }
}
