<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Http;

class EmbeddingService
{
    private string $provider;

    private string $model;

    private int $dimensions;

    public function __construct(?string $provider = null, ?string $model = null)
    {
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

        return new self(
            $config['embedding_provider'] ?? null,
            $config['embedding_model'] ?? null
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

        return match ($this->provider) {
            'openai' => $this->embedWithOpenAI($texts),
            default => throw new \InvalidArgumentException("Unsupported embedding provider: {$this->provider}"),
        };
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
     * Generate embeddings using OpenAI API.
     *
     * @param  array<string>  $texts
     * @return array<array<float>>
     */
    private function embedWithOpenAI(array $texts): array
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('OpenAI API key is not configured');
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/embeddings', [
            'model' => $this->model,
            'input' => $texts,
            'dimensions' => $this->dimensions,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "OpenAI embeddings API error: {$response->status()} - {$response->body()}"
            );
        }

        $data = $response->json();

        // Sort by index to ensure correct order
        $embeddings = collect($data['data'])
            ->sortBy('index')
            ->pluck('embedding')
            ->toArray();

        return $embeddings;
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

        // Default dimensions for common models
        return match ($model) {
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'text-embedding-ada-002' => 1536,
            default => 1536,
        };
    }
}
