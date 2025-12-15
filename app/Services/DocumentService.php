<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\EmbeddingStatus;
use App\Enums\Visibility;
use App\Jobs\ProcessDocumentForKnowledgeBase;
use App\Models\Document;
use App\Models\Folder;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    /**
     * The disk to use for document storage.
     */
    protected string $disk = 'documents';

    /**
     * Upload a new document.
     */
    public function upload(
        UploadedFile $file,
        User $user,
        Visibility $visibility = Visibility::Private,
        ?string $name = null,
        ?string $folderId = null
    ): Document {
        // Determine document type from extension
        $extension = strtolower($file->getClientOriginalExtension());
        $type = DocumentType::fromExtension($extension);

        // Generate storage path: {user_id}/documents/{doc_id}/{filename}
        // We'll create the document first to get the ID
        $document = Document::create([
            'user_id' => $user->id,
            'organization_id' => $visibility === Visibility::Organization ? $user->organization_id : null,
            'folder_id' => $folderId,
            'name' => $name ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'type' => $type,
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'visibility' => $visibility,
        ]);

        // Now store the file with the document ID in the path
        $storagePath = "{$user->id}/documents/{$document->id}/{$file->getClientOriginalName()}";
        Storage::disk($this->disk)->put($storagePath, $file->get());

        // Update the document with the file path
        $document->update(['file_path' => $storagePath]);

        return $document;
    }

    /**
     * Attach a document to a knowledge base.
     */
    public function attachToKnowledgeBase(Document $document, KnowledgeBase $knowledgeBase): void
    {
        // Check if already attached
        if ($document->knowledgeBases()->where('knowledge_base_id', $knowledgeBase->id)->exists()) {
            return;
        }

        // Attach with pending status
        $document->knowledgeBases()->attach($knowledgeBase->id, [
            'embedding_status' => EmbeddingStatus::Pending->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dispatch processing job
        ProcessDocumentForKnowledgeBase::dispatch($document, $knowledgeBase);
    }

    /**
     * Detach a document from a knowledge base.
     */
    public function detachFromKnowledgeBase(Document $document, KnowledgeBase $knowledgeBase): void
    {
        // Delete chunks associated with this document in this KB
        $knowledgeBase->chunks()
            ->where('document_id', $document->id)
            ->delete();

        // Detach the document
        $document->knowledgeBases()->detach($knowledgeBase->id);

        // Update KB counts
        $knowledgeBase->updateCounts();
    }

    /**
     * Get a temporary URL for downloading the document.
     */
    public function getTemporaryUrl(Document $document, int $minutes = 30): ?string
    {
        if (! $document->file_path) {
            return null;
        }

        $storage = Storage::disk($this->disk);

        if (! $storage->exists($document->file_path)) {
            return null;
        }

        return $storage->temporaryUrl(
            $document->file_path,
            now()->addMinutes($minutes)
        );
    }

    /**
     * Move a document to a different folder.
     */
    public function moveToFolder(Document $document, ?Folder $folder): Document
    {
        $document->update([
            'folder_id' => $folder?->id,
        ]);

        return $document->fresh();
    }

    /**
     * Update document visibility.
     */
    public function updateVisibility(Document $document, Visibility $visibility, User $user): Document
    {
        $organizationId = null;

        if ($visibility === Visibility::Organization) {
            if (! $user->organization_id) {
                throw new \RuntimeException('User must belong to an organization to share documents.');
            }
            $organizationId = $user->organization_id;
        }

        $document->update([
            'visibility' => $visibility,
            'organization_id' => $organizationId,
        ]);

        return $document->fresh();
    }

    /**
     * Delete a document and its associated resources.
     */
    public function delete(Document $document): void
    {
        // Delete chunks in all KBs
        $document->chunks()->delete();

        // Detach from all KBs
        $document->knowledgeBases()->detach();

        // Delete file from storage
        if ($document->file_path) {
            Storage::disk($this->disk)->delete($document->file_path);
        }

        // Soft delete the document
        $document->delete();
    }

    /**
     * Permanently delete a document.
     */
    public function forceDelete(Document $document): void
    {
        // Delete chunks in all KBs
        $document->chunks()->delete();

        // Detach from all KBs
        $document->knowledgeBases()->detach();

        // Delete file from storage
        if ($document->file_path) {
            Storage::disk($this->disk)->delete($document->file_path);
        }

        // Permanently delete the document
        $document->forceDelete();
    }

    /**
     * Get document content for parsing.
     */
    public function getContent(Document $document): ?string
    {
        if (! $document->file_path) {
            return null;
        }

        $storage = Storage::disk($this->disk);

        if (! $storage->exists($document->file_path)) {
            return null;
        }

        return $storage->get($document->file_path);
    }
}
