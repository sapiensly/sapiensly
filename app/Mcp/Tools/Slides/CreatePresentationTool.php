<?php

namespace App\Mcp\Tools\Slides;

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Mcp\Tools\SapiensTool;
use App\Models\Document;
use App\Models\User;
use App\Services\Slides\DeckValidator;
use App\Services\Slides\DeckVersioner;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a presentation (slide deck) the user can open, present full-screen and share. You author a CONSTRAINED deck manifest — never HTML: pick a layout per slide and fill its fields; the platform renders it with the organization\'s brand, guaranteeing a polished result. Layouts: title, section, bullets (2-5), two_column, big_number, metrics (2-4 KPIs), chart (bar/hbar/line/area/pie/donut/radar), quote, timeline (2-6 milestones), roadmap (gantt-style lanes over 2-8 periods), table (up to 4x5), closing. Copy budgets are enforced (short lines; one message per slide — split when in doubt) and validation errors name the exact slide and field so you can correct and retry. Returns the presentation URL.')]
class CreatePresentationTool extends SapiensTool
{
    protected const ABILITY = 'data:write';

    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('create', Document::class)) {
            return Response::error('You do not have permission to create documents.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'theme' => ['nullable', 'string', 'in:'.implode(',', DeckValidator::THEMES)],
            'slides' => ['required', 'array'],
        ]);

        $deck = [
            'title' => $validated['name'],
            'theme' => $validated['theme'] ?? 'executive',
            'slides' => array_values($validated['slides']),
        ];

        $errors = app(DeckValidator::class)->validate($deck);
        if ($errors !== []) {
            return Response::error("The deck is invalid — fix these and retry:\n- ".implode("\n- ", $errors));
        }

        $document = Document::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'name' => $validated['name'],
            'keywords' => [],
            'type' => DocumentType::Deck,
            'body' => json_encode($deck, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'metadata' => [
                'theme' => $deck['theme'],
                'slide_count' => count($deck['slides']),
            ],
        ]);

        app(DeckVersioner::class)->record($document, $deck, 'create', $user);

        return Response::json([
            'created' => true,
            'document_id' => $document->id,
            'name' => $document->name,
            'theme' => $deck['theme'],
            'slide_count' => count($deck['slides']),
            'url' => route('slides.present', ['document' => $document->id]),
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The presentation title (also the deck cover fallback).')->required(),
            'theme' => $schema->string()->enum(DeckValidator::THEMES)->description('Visual theme (default executive). The organization brand palette is applied on top automatically.'),
            'slides' => $schema->array()->description('Ordered slide objects, each {layout, ...fields, notes?}. Layouts and fields: title {title, subtitle?, meta?}; section {title, kicker?}; bullets {title, bullets: 2-5 short strings, kicker?}; two_column {title, left: {heading, items: 1-4}, right: {heading, items: 1-4}}; big_number {value, label, kicker?, delta?, context?}; metrics {title?, items: 2-4 of {value, label, delta?}}; chart {title, chart_type: bar|hbar|line|area|pie|donut|radar, labels: 2-12, series: [{name, data: one number per label}] (pie/donut: exactly 1 series; radar needs 3+ labels as its axes), takeaway?}; quote {quote, attribution?, role?}; timeline {title, kicker?, items: 2-6 of {label, title, description?, status?: done|active|upcoming}}; roadmap {title, kicker?, periods: 2-8 short time-axis headers (e.g. Q1…Q4 or months), lanes: 1-5 of {name, bars: 1-4 of {label, start, end (1-based period indexes, start<=end), status?: done|active|upcoming}}} — the presentation-ready gantt for plans/roadmaps; table {title, columns: 2-4 short strings, rows: 1-5 arrays with one cell per column}; closing {title, subtitle?, bullets?: 1-3, cta?}. LIVE DATA: a chart may carry data_source {app_slug, object, group_by (field slug), aggregation: count|sum|avg|min|max, field? (required unless count), bucket?: day|week|month|quarter|year} and a metrics item may carry value_source {app_slug, object, aggregation, field?} — the platform then re-aggregates tenant app records on every open (static labels/series/value become the fallback). Keep copy tight — budgets are enforced.')->required(),
        ];
    }
}
