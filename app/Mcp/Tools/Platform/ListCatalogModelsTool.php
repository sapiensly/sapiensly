<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\AiCatalogModel;
use App\Services\AiProviderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('The platform model catalog: every registered model with its driver, capability (chat, embeddings, vision, image, transcription, speech, rerank), whether it is enabled for use, its context window and per-million-token prices, and whether its provider is actually connected. Filter by driver, capability or enabled state. A model whose provider has no key cannot be enabled, and a model with no price meters as $0 — both are flagged here. Read-only; toggle with manage_catalog_model.')]
class ListCatalogModelsTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'driver' => ['sometimes', 'nullable', 'string', 'max:60'],
            'capability' => ['sometimes', 'nullable', 'string', 'max:40'],
            'enabled' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $service = app(AiProviderService::class);

        $query = AiCatalogModel::query();

        if (! empty($validated['driver'])) {
            $query->where('driver', $validated['driver']);
        }
        if (! empty($validated['capability'])) {
            $query->where('capability', $validated['capability']);
        }
        if ($request->has('enabled')) {
            $query->where('is_enabled', (bool) $validated['enabled']);
        }
        if (! empty($validated['search'])) {
            $needle = '%'.strtolower($validated['search']).'%';
            $query->where(function ($inner) use ($needle) {
                $inner->whereRaw('lower(model_id) like ?', [$needle])
                    ->orWhereRaw('lower(label) like ?', [$needle]);
            });
        }

        $total = (clone $query)->count();

        $models = $query
            ->orderBy('driver')
            ->orderBy('capability')
            ->orderBy('label')
            ->limit((int) ($validated['limit'] ?? 100))
            ->get();

        $configured = [];

        return Response::json([
            'total_matching' => $total,
            'returned' => $models->count(),
            'models' => $models->map(function (AiCatalogModel $model) use ($service, &$configured) {
                $providerConnected = $configured[$model->driver] ??= $service->isDriverConfigured($model->driver);

                return [
                    'id' => (string) $model->id,
                    'driver' => $model->driver,
                    'model_id' => $model->model_id,
                    'label' => $model->label,
                    'capability' => $model->capability,
                    'enabled' => (bool) $model->is_enabled,
                    'provider_connected' => $providerConnected,
                    'context_window' => $model->context_window,
                    'input_price_per_mtok' => $model->input_price_per_mtok,
                    'output_price_per_mtok' => $model->output_price_per_mtok,
                    'unpriced' => $model->input_price_per_mtok === null && $model->output_price_per_mtok === null,
                ];
            })->values(),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'driver' => $schema->string()->description('Only models from this driver.'),
            'capability' => $schema->string()->description('chat | embeddings | vision | image | transcription | speech | rerank.'),
            'enabled' => $schema->boolean()->description('Only enabled (true) or only disabled (false) models.'),
            'search' => $schema->string()->description('Match against model id or label.'),
            'limit' => $schema->integer()->description('How many to return, 1-500. Default 100.'),
        ];
    }
}
