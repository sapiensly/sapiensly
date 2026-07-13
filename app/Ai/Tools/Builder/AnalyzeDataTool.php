<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Models\User;
use App\Services\Analyst\AnalystCore;
use App\Services\Analyst\FindingBlock;
use App\Services\Manifest\AppManifestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The analyst, in the builder's own chat.
 *
 * The builder had two minds about the same data: the «Agregar gráfica» panel
 * asked {@see AnalystCore}, while the chat guessed charts from a field list
 * through an entirely different path — so the two could look at one board and
 * disagree about what it needed. This is the same engine, so they can't.
 *
 * It reads the real rows of every object (native and connected), computes the
 * facts, and hands back the analyses worth showing — each with a manifest block
 * ready for propose_change, so the model never has to invent a chart spec.
 */
class AnalyzeDataTool implements Tool
{
    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private ?User $actor = null,
    ) {}

    public function name(): string
    {
        return 'analyze_data';
    }

    public function description(): string
    {
        return <<<'DESC'
Ask the expert analyst what this app's data is actually SAYING, and get the
analyses worth putting on a board — each grounded in a real computed number.
It reads the live rows of every object (internal and connected) and finds:
concentration (Pareto), trends with a run-rate ETA, correlations between two
measures (scatter), volume against a rate on a dual axis, outliers with the day
they happened, ratios the board doesn't carry, flows between two categoricals
(sankey), composition over time, spread the average hides (box), seasonality, and
joins across two sources. It also reports data-confidence flags.

Prefer this over choosing a chart from a field list: every finding comes back
with a ready manifest `block` (chart, gauge, insight or stat) you pass straight to
propose_change after minting an id. Pass `exclude` with the `semantic_key` values
already on the page so it never proposes an analysis the board already shows.
Returns { domain, sources, total_rows, data_quality, gaps, findings: [{id, kind,
title, why, flag, semantic_key, object_id, block}] }.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'exclude' => $schema->array()
                ->description('semantic_key values the page already shows, so they are not proposed again.'),
            'max' => $schema->integer()
                ->description('How many findings to return (default 5, max 20).'),
            'lang' => $schema->string()
                ->description('Language of the narrative: es (default) or en.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        $manifest = $this->manifestService->getActiveManifest($this->appModel);
        if (! is_array($manifest)) {
            return json_encode(['error' => 'This app has no active manifest yet — build it before analysing its data.'], JSON_THROW_ON_ERROR);
        }

        $exclude = array_values(array_filter(
            is_array($args['exclude'] ?? null) ? $args['exclude'] : [],
            'is_string',
        ));
        $max = is_numeric($args['max'] ?? null)
            ? max(1, min(20, (int) $args['max']))
            : AnalystCore::DEFAULT_MAX;

        try {
            $analysis = app(AnalystCore::class)->analyze(
                $this->appModel,
                $manifest,
                $this->actor,
                ($args['lang'] ?? 'es') === 'en' ? 'en' : 'es',
                $exclude,
                $max,
            );
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR);
        }

        if ($analysis['sources'] === 0) {
            return json_encode([
                'sources' => 0,
                'findings' => [],
                'message' => 'No object in this app returned any rows — there is nothing to analyse yet. Seed or connect data first.',
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'domain' => $analysis['domain'],
            'sources' => $analysis['sources'],
            'total_rows' => $analysis['total_rows'],
            'data_quality' => $analysis['data_quality'],
            'gaps' => $analysis['gaps'],
            'findings' => array_map(function (array $finding): array {
                $rendered = FindingBlock::forFinding($finding);

                return [
                    'id' => $finding['id'],
                    'kind' => $finding['kind'],
                    'title' => $finding['title'],
                    'why' => $finding['why'],
                    'flag' => $finding['flag'],
                    'semantic_key' => $finding['semantic_key'],
                    'object_id' => FindingBlock::objectId($finding),
                    'block' => $rendered['block'],
                ];
            }, $analysis['findings']),
        ], JSON_THROW_ON_ERROR);
    }
}
