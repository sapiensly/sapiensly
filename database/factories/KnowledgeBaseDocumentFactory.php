<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\KnowledgeBaseStatus;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeBaseDocument>
 */
class KnowledgeBaseDocumentFactory extends Factory
{
    public function definition(): array
    {
        $type = fake()->randomElement(DocumentType::cases());

        return [
            'knowledge_base_id' => KnowledgeBase::factory(),
            'type' => $type,
            'source' => $type === DocumentType::Url
                ? fake()->url()
                : fake()->word().'.'.$type->extension(),
            'original_filename' => $type === DocumentType::Url
                ? null
                : fake()->word().'.'.$type->extension(),
            'content' => fake()->paragraphs(3, true),
            'metadata' => [],
            'embedding_status' => KnowledgeBaseStatus::Pending,
            'error_message' => null,
            'file_path' => $type === DocumentType::Url
                ? null
                : 'documents/'.fake()->uuid().'.'.$type->extension(),
            'file_size' => $type === DocumentType::Url
                ? null
                : fake()->numberBetween(1000, 1000000),
        ];
    }

    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::Pdf,
            'source' => fake()->word().'.pdf',
            'original_filename' => fake()->word().'.pdf',
            'file_path' => 'documents/'.fake()->uuid().'.pdf',
        ]);
    }

    public function url(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentType::Url,
            'source' => fake()->url(),
            'original_filename' => null,
            'file_path' => null,
            'file_size' => null,
        ]);
    }

    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'embedding_status' => KnowledgeBaseStatus::Ready,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'embedding_status' => KnowledgeBaseStatus::Failed,
            'error_message' => 'Failed to process document',
        ]);
    }
}
