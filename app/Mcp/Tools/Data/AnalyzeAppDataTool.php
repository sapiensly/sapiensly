<?php

namespace App\Mcp\Tools\Data;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Analyst\AnalystCore;
use App\Services\Analyst\FindingBlock;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * The analyst, exposed. Same {@see AnalystCore} the App Builder's «Agregar
 * gráfica» panel asks — so MCP clients, agents and chatbots read the data the
 * way the product does, instead of each re-deriving "what chart fits this?".
 */
#[Description('Ask the expert analyst what an app\'s data is actually saying. It reads the real rows of every object (native and connected), computes the facts — concentration, trend and its run-rate, ratios the board does not carry, joins across two sources, outliers — and returns the analyses worth showing, each grounded in a number and ranked by business relevance, plus data-confidence flags. Every finding comes with a ready manifest `block` (chart, gauge or insight): add one with propose_change (mint an id) to put it on a page. Pass `exclude` (semantic_key values you already show) so it never proposes an analysis the board already has. This is the same engine behind the builder\'s "Agregar gráfica" panel — prefer it over guessing a chart from a field list.')]
class AnalyzeAppDataTool extends SapiensTool
{
    protected const ABILITY = 'data:read';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'exclude' => ['sometimes', 'array'],
            'exclude.*' => ['string'],
            'max' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'lang' => ['sometimes', 'in:es,en'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        $manifest = app(AppManifestService::class)->getActiveManifest($app);
        if (! is_array($manifest)) {
            return Response::error("App '{$validated['app_slug']}' has no active manifest yet — build it first.");
        }

        $analysis = app(AnalystCore::class)->analyze(
            $app,
            $manifest,
            $user,
            $validated['lang'] ?? 'es',
            array_values($validated['exclude'] ?? []),
            $validated['max'] ?? AnalystCore::DEFAULT_MAX,
        );

        if ($analysis['sources'] === 0) {
            return Response::json([
                'sources' => 0,
                'findings' => [],
                'message' => 'No object in this app returned any rows — there is nothing to analyse yet.',
            ]);
        }

        return Response::json([
            'domain' => $analysis['domain'],
            'sources' => $analysis['sources'],
            'total_rows' => $analysis['total_rows'],
            'sources_detail' => $analysis['sources_detail'],
            'data_quality' => $analysis['data_quality'],
            'gaps' => $analysis['gaps'],
            'findings' => array_map(fn (array $f): array => $this->finding($f), $analysis['findings']),
        ]);
    }

    /**
     * A finding as an agent can use it: the reading, and the block that shows it.
     *
     * @param  array<string, mixed>  $finding
     * @return array<string, mixed>
     */
    private function finding(array $finding): array
    {
        $rendered = FindingBlock::forFinding($finding);

        return [
            'id' => $finding['id'],
            'kind' => $finding['kind'],
            'title' => $finding['title'],
            'why' => $finding['why'],
            'flag' => $finding['flag'],
            'score' => $finding['score'] ?? null,
            'semantic_key' => $finding['semantic_key'],
            'object_id' => FindingBlock::objectId($finding),
            'block' => $rendered['block'],
        ];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('The app whose data to analyse.')
                ->required(),
            'exclude' => $schema->array()
                ->description('semantic_key values the surface already shows, so they are not proposed again (each finding returns its own semantic_key).'),
            'max' => $schema->integer()
                ->description('How many findings to return (default 5, max 20).'),
            'lang' => $schema->string()
                ->description('Language of the narrative: es (default) or en.'),
        ];
    }
}
