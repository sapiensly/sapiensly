<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Models\AiCatalogModel;
use App\Services\Ai\AiDefaults;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Which model each part of the platform routes to. Every module (chat, builder, landing_builder, landing_director, flows, chatbots, embeddings, coding, ocr_pdf, image_vision, image_generation, audio_recognition, speech_generation, reranking) with its configured primary and fallback, the capability its models must have, and whether the chosen model is still enabled — a default pointing at a disabled model is reported as broken rather than left to fail at call time. Unset modules show the hard-coded fallback that applies instead. Read-only; change with set_ai_defaults.')]
class GetAiDefaultsTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $defaults = app(AiDefaults::class);

        $catalog = AiCatalogModel::query()
            ->get()
            ->keyBy(fn (AiCatalogModel $model) => (string) $model->id);

        $modules = [];
        $broken = [];

        foreach (AiDefaults::MODULES as $module) {
            $capability = $defaults->capabilityFor($module);

            $slots = [];
            foreach (['primary', 'fallback'] as $slot) {
                $id = $slot === 'primary' ? $defaults->primaryId($module) : $defaults->fallbackId($module);
                $model = $id === null ? null : $catalog->get($id);

                if ($id !== null && $model === null) {
                    $broken[] = "{$module}.{$slot} points at catalog id {$id}, which no longer exists.";
                } elseif ($model !== null && ! $model->is_enabled) {
                    $broken[] = "{$module}.{$slot} is '{$model->label}', which is DISABLED.";
                }

                $slots[$slot] = $model === null ? null : [
                    'catalog_id' => (string) $model->id,
                    'driver' => $model->driver,
                    'model' => $model->model_id,
                    'label' => $model->label,
                    'enabled' => (bool) $model->is_enabled,
                ];
            }

            $modules[$module] = [
                'capability' => $capability,
                'primary' => $slots['primary'],
                'fallback' => $slots['fallback'],
                'configured' => $slots['primary'] !== null,
                'hard_default' => $defaults->hardDefaultFor($module),
            ];
        }

        return Response::json([
            'modules' => $modules,
            'capability_modules' => AiDefaults::CAPABILITY_MODULES,
            'problems' => $broken,
            'note' => 'A module with no primary falls back to its hard default (chat only); specialized capabilities with nothing configured report "not configured" instead of calling a wrong model.',
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
