<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\AiCatalogModel;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Turn a catalog model on or off for the whole platform, rename its label, or probe it with a real minimal call. Actions: enable (refused when the provider has no key — an enabled model nothing can reach only produces failures at call time), disable, relabel, test. Disabling a model that is a module DEFAULT is refused too: pick a new default with set_ai_defaults first, or that module silently falls back. Identify the model by its catalog id, or by driver + model_id. Audited.')]
class ManageCatalogModelTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'model_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'driver' => ['sometimes', 'nullable', 'string', 'max:60'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'action' => ['required', 'string', 'in:enable,disable,relabel,test'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $catalogModel = $this->resolve($validated);

        if ($catalogModel === null) {
            return Response::error('Name the model: pass its catalog `model_id`, or `driver` + `model`. Find it with list_catalog_models.');
        }

        $service = app(AiProviderService::class);

        return match ($validated['action']) {
            'enable' => $this->enable($catalogModel, $actor, $service),
            'disable' => $this->disable($catalogModel, $actor),
            'relabel' => $this->relabel($catalogModel, $actor, $validated['label'] ?? null),
            'test' => $this->test($catalogModel, $service),
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolve(array $validated): ?AiCatalogModel
    {
        if (! empty($validated['model_id'])) {
            return AiCatalogModel::find($validated['model_id']);
        }

        if (! empty($validated['driver']) && ! empty($validated['model'])) {
            return AiCatalogModel::query()
                ->where('driver', $validated['driver'])
                ->where('model_id', $validated['model'])
                ->first();
        }

        return null;
    }

    private function enable(AiCatalogModel $model, User $actor, AiProviderService $service): Response
    {
        if ($model->is_enabled) {
            return Response::error("'{$model->label}' is already enabled.");
        }

        if (! $service->isDriverConfigured($model->driver)) {
            $label = AiProviderService::DRIVER_LABELS[$model->driver] ?? $model->driver;

            return Response::error(
                "Refused: {$label} has no API key, so this model could not actually be called. "
                .'Connect the provider first with manage_provider_key.'
            );
        }

        $model->update(['is_enabled' => true]);

        $this->audit(
            actor: $actor,
            summary: "Enabled model '{$model->label}' ({$model->driver}/{$model->model_id})",
            meta: ['driver' => $model->driver, 'model' => $model->model_id, 'capability' => $model->capability],
            targetType: 'ai_catalog_model',
            targetId: (string) $model->id,
            targetLabel: $model->label,
        );

        return Response::json([
            'action' => 'enable',
            'model' => $this->present($model->refresh()),
            'note' => $model->input_price_per_mtok === null && $model->output_price_per_mtok === null
                ? 'This model has NO price recorded, so its usage will meter as $0 and slip past budgets.'
                : null,
        ]);
    }

    private function disable(AiCatalogModel $model, User $actor): Response
    {
        if (! $model->is_enabled) {
            return Response::error("'{$model->label}' is already disabled.");
        }

        $usedBy = $this->modulesDefaultingTo($model);

        if ($usedBy !== []) {
            return Response::error(
                "Refused: '{$model->label}' is the configured default for ".implode(', ', $usedBy).'. '
                .'Point those modules at another model with set_ai_defaults first.'
            );
        }

        $model->update(['is_enabled' => false]);

        $this->audit(
            actor: $actor,
            summary: "Disabled model '{$model->label}' ({$model->driver}/{$model->model_id})",
            meta: ['driver' => $model->driver, 'model' => $model->model_id],
            targetType: 'ai_catalog_model',
            targetId: (string) $model->id,
            targetLabel: $model->label,
        );

        return Response::json([
            'action' => 'disable',
            'model' => $this->present($model->refresh()),
            'note' => 'Nothing can route to it any more; existing conversations that named it will fall back.',
        ]);
    }

    private function relabel(AiCatalogModel $model, User $actor, ?string $label): Response
    {
        $label = trim((string) $label);

        if ($label === '') {
            return Response::error('`label` is required for relabel.');
        }

        $previous = $model->label;
        $model->update(['label' => $label]);

        $this->audit(
            actor: $actor,
            summary: "Relabelled model '{$previous}' to '{$label}'",
            meta: ['from' => $previous, 'to' => $label],
            targetType: 'ai_catalog_model',
            targetId: (string) $model->id,
            targetLabel: $label,
        );

        return Response::json([
            'action' => 'relabel',
            'model' => $this->present($model->refresh()),
        ]);
    }

    private function test(AiCatalogModel $model, AiProviderService $service): Response
    {
        $result = $service->testCatalogModel($model->driver, $model->model_id, $model->capability);

        return Response::json([
            'action' => 'test',
            'model' => $this->present($model),
            'success' => $result['success'],
            'message' => $result['message'],
            'detail' => $result['detail'] ?? null,
        ]);
    }

    /**
     * The AI modules whose primary or fallback is this model — the ones that
     * would silently lose their configured routing if it were disabled.
     *
     * @return list<string>
     */
    private function modulesDefaultingTo(AiCatalogModel $model): array
    {
        $defaults = app(AiDefaults::class);
        $id = (string) $model->id;
        $modules = [];

        foreach (AiDefaults::MODULES as $module) {
            if ($defaults->primaryId($module) === $id || $defaults->fallbackId($module) === $id) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiCatalogModel $model): array
    {
        return [
            'id' => (string) $model->id,
            'driver' => $model->driver,
            'model_id' => $model->model_id,
            'label' => $model->label,
            'capability' => $model->capability,
            'enabled' => (bool) $model->is_enabled,
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'model_id' => $schema->string()->description('The catalog row id (from list_catalog_models).'),
            'driver' => $schema->string()->description('Driver, when identifying the model by driver + model.'),
            'model' => $schema->string()->description("The provider's model identifier, paired with driver."),
            'action' => $schema->string()->description('enable | disable | relabel | test')->required(),
            'label' => $schema->string()->description('New display label. Required for relabel.'),
        ];
    }
}
