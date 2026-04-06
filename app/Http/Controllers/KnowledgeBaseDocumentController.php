<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\KnowledgeBaseStatus;
use App\Jobs\ProcessKnowledgeBaseDocument;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KnowledgeBaseDocumentController extends Controller
{
    /**
     * The disk to use for document storage.
     */
    protected string $disk = 'documents';

    /**
     * Store a newly created document.
     */
    public function store(Request $request, KnowledgeBase $knowledgeBase): RedirectResponse
    {
        $this->authorize('view', $knowledgeBase);

        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240', // 10MB
                'mimes:pdf,txt,docx,doc,md,csv,json',
            ],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        // Determine document type from extension
        $type = match ($extension) {
            'pdf' => DocumentType::Pdf,
            'txt' => DocumentType::Txt,
            'docx', 'doc' => DocumentType::Docx,
            'md' => DocumentType::Md,
            'csv' => DocumentType::Csv,
            'json' => DocumentType::Json,
            default => throw new \InvalidArgumentException("Unsupported file type: {$extension}"),
        };

        // Generate unique filename to prevent collisions
        $filename = Str::ulid().'_'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$extension;

        // Build tenant-isolated path: {user_id}/knowledge-bases/{kb_id}/{filename}
        $userId = $request->user()->id;
        $storagePath = "{$userId}/knowledge-bases/{$knowledgeBase->id}";
        $fullPath = "{$storagePath}/{$filename}";

        // Store file to S3 (documents disk)
        Storage::disk($this->disk)->put($fullPath, file_get_contents($file->getRealPath()));

        // Create document record
        $document = $knowledgeBase->documents()->create([
            'type' => $type,
            'source' => $file->getClientOriginalName(),
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $fullPath,
            'file_size' => $file->getSize(),
            'embedding_status' => KnowledgeBaseStatus::Pending,
        ]);

        // Dispatch processing job
        ProcessKnowledgeBaseDocument::dispatch($document);

        return back()->with('success', __('Document uploaded and processing started.'));
    }

    /**
     * Store a URL document.
     */
    public function storeUrl(Request $request, KnowledgeBase $knowledgeBase): RedirectResponse
    {
        $this->authorize('view', $knowledgeBase);

        $request->validate([
            'url' => ['required', 'url', 'max:2000'],
        ]);

        // Create document record
        $document = $knowledgeBase->documents()->create([
            'type' => DocumentType::Url,
            'source' => $request->url,
            'embedding_status' => KnowledgeBaseStatus::Pending,
        ]);

        // Dispatch processing job
        ProcessKnowledgeBaseDocument::dispatch($document);

        return back()->with('success', __('URL added and processing started.'));
    }

    /**
     * Remove the specified document.
     */
    public function destroy(Request $request, KnowledgeBase $knowledgeBase, KnowledgeBaseDocument $document): RedirectResponse
    {
        $this->authorize('view', $knowledgeBase);

        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            abort(404);
        }

        // Delete file from S3 if exists
        if ($document->file_path && Storage::disk($this->disk)->exists($document->file_path)) {
            Storage::disk($this->disk)->delete($document->file_path);
        }

        // Delete document (chunks will cascade delete)
        $document->delete();

        // Update knowledge base counts
        $knowledgeBase->update([
            'document_count' => $knowledgeBase->documents()->count(),
            'chunk_count' => $knowledgeBase->chunks()->count(),
        ]);

        return back()->with('success', __('Document deleted.'));
    }

    /**
     * Reprocess a document.
     */
    public function reprocess(Request $request, KnowledgeBase $knowledgeBase, KnowledgeBaseDocument $document): RedirectResponse
    {
        $this->authorize('view', $knowledgeBase);

        if ($document->knowledge_base_id !== $knowledgeBase->id) {
            abort(404);
        }

        // Reset status
        $document->update([
            'embedding_status' => KnowledgeBaseStatus::Pending,
            'error_message' => null,
        ]);

        // Dispatch processing job
        ProcessKnowledgeBaseDocument::dispatch($document);

        return back()->with('success', __('Document reprocessing started.'));
    }
}
