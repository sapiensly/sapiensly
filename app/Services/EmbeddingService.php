<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use App\Models\User;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Enums\Lab;

class EmbeddingService
{
    private string $provider;

    private string $model;

    private int $dimensions;

    public function __construct(?string $provider = null, ?string $model = null, ?User $user = null)
    {
        // If a user is provided, try to resolve defaults from their configured AI providers
        if ($user && ($provider === null || $model === null)) {
            $aiProviderService = app(AiProviderService::class);
            $aiProviderService->applyRuntimeConfig($user);

            $defaultEmbeddings = $aiProviderService->getDefaultEmbeddingsProvider($user);
            if ($defaultEmbeddings) {
                $embeddingModels = $defaultEmbeddings->getEmbeddingModels();
                if (! empty($embeddingModels)) {
                    $provider ??= $defaultEmbeddings->driver;
                    $model ??= $embeddingModels[0]['id'];
                }
            }
        }

        $this->provider = $provider ?? config('services.embeddings.default_provider', 'openai');
        $this->model = $model ?? config('services.embeddings.default_model', 'text-embedding-3-small');
        $this->dimensions = $this->getDimensionsForModel($this->model);
    }

    /**
     * Create an EmbeddingService configured for a specific KnowledgeBase.
     */
    public static function forKnowledgeBase(KnowledgeBase $knowledgeBase): self
    {
        $config = $knowledgeBase->config ?? [];
        $user = $knowledgeBase->user;

        return new self(
            $config['embedding_provider'] ?? null,
            $config['embedding_model'] ?? null,
            $user,
        );
    }

    /**
     * Generate an embedding for a single text.
     *
     * @return array<float> The embedding vector
     */
    public function embed(string $text): array
    {
        $embeddings = $this->embedBatch([$text]);

        return $embeddings[0];
    }

    /**
     * Generate embeddings for multiple texts in a batch.
     *
     * @param  array<string>  $texts
     * @return array<array<float>> Array of embedding vectors
     */
    public function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $lab = $this->getLabProvider();

        $response = Embeddings::for($texts)
            ->dimensions($this->dimensions)
            ->generate(provider: $lab, model: $this->model);

        return $response->embeddings;
    }

    /**
     * Get the current model being used.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the dimensions of the embedding vectors.
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Get the provider being used.
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * Get the Lab enum value for the configured provider.
     */
    private function getLabProvider(): Lab
    {
        return match ($this->provider) {
            'openai' => Lab::OpenAI,
            'anthropic' => Lab::Anthropic,
            'gemini' => Lab::Gemini,
            'mistral' => Lab::Mistral,
            'cohere' => Lab::Cohere,
            'jina' => Lab::Jina,
            'voyageai' => Lab::VoyageAI,
            default => Lab::OpenAI,
        };
    }

    /**
     * Get the dimensions for a specific model.
     */
    private function getDimensionsForModel(string $model): int
    {
        $models = config('services.embeddings.providers.openai.models', []);

        if (isset($models[$model]['dimensions'])) {
            return $models[$model]['dimensions'];
        }

        return match ($model) {
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'text-embedding-ada-002' => 1536,
            default => 1536,
        };
    }
}
