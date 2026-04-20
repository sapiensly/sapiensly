<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Http\Response;

/**
 * Renders documents that their owner has marked as public. No authentication:
 * anyone with the link can view. Currently scoped to Artifact documents — the
 * only type where publishing a standalone page makes sense — and served with
 * `Content-Security-Policy: sandbox` so the browser treats the response as
 * an opaque origin and the embedded scripts cannot touch app cookies.
 */
class PublicDocumentController extends Controller
{
    public function show(string $id): Response
    {
        $document = Document::query()->find($id);

        if ($document === null
            || ! $document->isPublic()
            || $document->type !== DocumentType::Artifact
            || $document->body === null
        ) {
            abort(404);
        }

        return response((string) $document->body, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => 'sandbox allow-scripts allow-forms allow-popups',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
