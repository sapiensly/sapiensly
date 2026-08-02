<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Services\Apps\Docs\AppDocs;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two documents an app carries: its user guide and its technical sheet.
 *
 * Both are derived from the current manifest on every request — see
 * {@see AppDocs} for why nothing is stored — so this controller has no state to
 * manage and no cache to invalidate. It gates on the same visibility as the
 * builder: whoever may open the app may read what it is.
 */
class AppDocsController extends Controller
{
    public function __construct(private readonly AppDocs $docs) {}

    public function show(Request $request, App $app): Response
    {
        abort_unless($app->isVisibleTo($request->user()), 403);

        $documents = $this->docs->forApp($app);

        return inertia('apps/Docs', [
            'app' => [
                'id' => $app->id,
                'slug' => $app->slug,
                'name' => $app->name,
                'icon' => $app->icon,
                'kind' => $app->kind,
                'version' => $app->currentVersion?->version_number,
            ],
            'documents' => [
                'manual' => $documents['manual']->toArray(),
                'technical' => $documents['technical']->toArray(),
            ],
            'kind' => in_array($request->query('kind'), AppDocs::KINDS, true)
                ? $request->query('kind')
                : 'manual',
        ]);
    }

    /**
     * The same document as a Markdown file — for reading outside the browser,
     * and for pasting into whatever else the reader works in.
     */
    public function download(Request $request, App $app, string $kind): StreamedResponse
    {
        abort_unless($app->isVisibleTo($request->user()), 403);
        abort_unless(in_array($kind, AppDocs::KINDS, true), 404);

        $doc = $this->docs->of($app, $kind);
        $filename = ($app->slug ?? 'app').'-'.$kind.'.md';

        return response()->streamDownload(
            fn () => print $doc->toMarkdown(),
            $filename,
            ['Content-Type' => 'text/markdown; charset=UTF-8'],
        );
    }
}
