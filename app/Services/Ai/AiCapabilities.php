<?php

namespace App\Services\Ai;

use App\Models\AiCatalogModel;
use Laravel\Ai\Enums\Lab;

/**
 * Resolves the configured handler — concrete model + provider — for each
 * specialized capability category (image generation, OCR, transcription, speech,
 * reranking). Single source of truth shared by the capability tools (which model
 * to actually call) and the agent prompt guidance (so an agent knows which task
 * it can hand off and through which tool).
 *
 * A category resolves to its admin-configured PRIMARY model, falling back to the
 * FALLBACK; specialized capabilities have no universal hard default, so an
 * unconfigured category resolves to null and is simply not offered.
 */
class AiCapabilities
{
    /**
     * Specialized categories that an agent can invoke as a tool, mapped to the
     * tool name the agent calls. (Embeddings, coding and vision are consumed
     * elsewhere — embeddings by RAG, vision by OCR/native attachments — so they
     * are not directly callable tools.)
     *
     * @var array<string, string>
     */
    public const TOOLS = [
        'image_generation' => 'generate_image',
        'ocr_pdf' => 'ocr_document',
        'image_vision' => 'ocr_document',
        'audio_recognition' => 'transcribe_audio',
        'speech_generation' => 'synthesize_speech',
        'reranking' => 'rerank',
    ];

    public function __construct(private readonly AiDefaults $defaults) {}

    /**
     * The configured handler for a category, or null when unconfigured.
     *
     * @return array{model: string, driver: string, provider: Lab}|null
     */
    public function resolve(string $category): ?array
    {
        foreach ([$this->defaults->primaryId($category), $this->defaults->fallbackId($category)] as $catalogId) {
            if ($catalogId === null) {
                continue;
            }

            $row = AiCatalogModel::query()->whereKey($catalogId)->first(['model_id', 'driver']);
            if ($row === null) {
                continue;
            }

            $provider = Lab::tryFrom($row->driver);
            if ($provider === null) {
                continue;
            }

            return ['model' => (string) $row->model_id, 'driver' => (string) $row->driver, 'provider' => $provider];
        }

        return null;
    }

    public function isConfigured(string $category): bool
    {
        return $this->resolve($category) !== null;
    }

    /**
     * Configured capability handlers, keyed by tool name, for prompt guidance and
     * tool registration. Deduplicated by tool (ocr_pdf/image_vision share one tool;
     * the first configured wins for the model shown).
     *
     * @return array<string, array{tool: string, categories: list<string>, model: string, provider: Lab}>
     */
    public function configuredTools(): array
    {
        $out = [];
        foreach (self::TOOLS as $category => $tool) {
            $resolved = $this->resolve($category);
            if ($resolved === null) {
                continue;
            }

            if (! isset($out[$tool])) {
                $out[$tool] = ['tool' => $tool, 'categories' => [], 'model' => $resolved['model'], 'provider' => $resolved['provider']];
            }
            $out[$tool]['categories'][] = $category;
        }

        return $out;
    }
}
