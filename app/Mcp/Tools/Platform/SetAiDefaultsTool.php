<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\AiCatalogModel;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Point one AI module at a model, platform-wide. Name the module (chat, builder, landing_builder, landing_director, flows, chatbots, embeddings, coding, ocr_pdf, image_vision, image_generation, audio_recognition, speech_generation, reranking) and the slot (primary or fallback), then either a catalog model_id or driver + model. Pass clear=true to unset the slot. Guarded: the model must have the CAPABILITY the module needs (an embeddings model cannot serve chat) and must be enabled, so a default can never be set to something that would fail at call time. This changes routing for every organization. Audited.')]
class SetAiDefaultsTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'module' => ['required', 'string', 'max:60'],
            'slot' => ['sometimes', 'string', 'in:primary,fallback'],
            'model_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'driver' => ['sometimes', 'nullable', 'string', 'max:60'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'clear' => ['sometimes', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $module = $validated['module'];
        $slot = $validated['slot'] ?? 'primary';

        if (! in_array($module, AiDefaults::MODULES, true)) {
            return Response::error(
                "Unknown module '{$module}'. Known modules: ".implode(', ', AiDefaults::MODULES)
            );
        }

        $defaults = app(AiDefaults::class);
        $previousId = $slot === 'primary' ? $defaults->primaryId($module) : $defaults->fallbackId($module);

        if (! empty($validated['clear'])) {
            $defaults->setCatalogId($module, $slot, null);

            $this->audit(
                actor: $actor,
                summary: "Cleared the {$slot} model for module '{$module}'",
                meta: ['module' => $module, 'slot' => $slot, 'previous_catalog_id' => $previousId],
                targetType: 'ai_default',
                targetLabel: $module,
            );

            return Response::json([
                'module' => $module,
                'slot' => $slot,
                'cleared' => true,
                'note' => $module === 'landing_builder' || $module === 'landing_director'
                    ? 'This module is optional — with it unset, the builder chain applies instead.'
                    : 'The module now resolves to its hard default (chat) or reports "not configured" (specialized capabilities).',
            ]);
        }

        $catalogModel = $this->resolve($validated);

        if ($catalogModel === null) {
            return Response::error('Name the model: pass `model_id`, or `driver` + `model`, or `clear: true` to unset. Find ids with list_catalog_models.');
        }

        $required = $defaults->capabilityFor($module);

        // OCR, vision and image generation may also route through the broker,
        // mirroring the admin picker — anything else must match outright.
        $brokerEligible = in_array($module, ['ocr_pdf', 'image_vision', 'image_generation'], true)
            && $catalogModel->driver === 'openrouter';

        if ($catalogModel->capability !== $required && ! $brokerEligible) {
            return Response::error(
                "Refused: module '{$module}' needs a '{$required}' model, but '{$catalogModel->label}' is '{$catalogModel->capability}'. "
                .'It would fail on every call.'
            );
        }

        if (! $catalogModel->is_enabled) {
            return Response::error(
                "Refused: '{$catalogModel->label}' is disabled. Enable it with manage_catalog_model first, "
                .'or this module would route to something nothing can call.'
            );
        }

        $defaults->setCatalogId($module, $slot, (string) $catalogModel->id);

        $this->audit(
            actor: $actor,
            summary: "Set {$module}.{$slot} to '{$catalogModel->label}' ({$catalogModel->driver}/{$catalogModel->model_id})",
            meta: [
                'module' => $module,
                'slot' => $slot,
                'catalog_id' => (string) $catalogModel->id,
                'previous_catalog_id' => $previousId,
            ],
            targetType: 'ai_default',
            targetId: (string) $catalogModel->id,
            targetLabel: $module,
        );

        return Response::json([
            'module' => $module,
            'slot' => $slot,
            'model' => [
                'catalog_id' => (string) $catalogModel->id,
                'driver' => $catalogModel->driver,
                'model' => $catalogModel->model_id,
                'label' => $catalogModel->label,
                'capability' => $catalogModel->capability,
                'input_price_per_mtok' => $catalogModel->input_price_per_mtok,
                'output_price_per_mtok' => $catalogModel->output_price_per_mtok,
            ],
            'previous_catalog_id' => $previousId,
            'note' => 'Applies to every organization from the next call onward.',
        ]);
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

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('The module to route: chat, builder, landing_builder, landing_director, embeddings, …')->required(),
            'slot' => $schema->string()->description('primary | fallback. Default primary.'),
            'model_id' => $schema->string()->description('Catalog row id (from list_catalog_models).'),
            'driver' => $schema->string()->description('Driver, when identifying the model by driver + model.'),
            'model' => $schema->string()->description("The provider's model identifier, paired with driver."),
            'clear' => $schema->boolean()->description('Unset this slot instead of assigning a model.'),
        ];
    }
}
