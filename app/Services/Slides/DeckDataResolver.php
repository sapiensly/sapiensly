<?php

namespace App\Services\Slides;

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\RecordQueryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolves a deck's live data bindings at present-time: chart slides with a
 * `data_source` get fresh labels + series, metric items with a `value_source`
 * get a fresh value — aggregated over the viewer's tenant-scoped app records.
 * This is what makes a Sapiensly deck "alive" where exported slideware is a
 * snapshot.
 *
 * Resolution is strictly best-effort: any failure (app deleted, field renamed,
 * no access) leaves the slide's static fallback content untouched — a deck
 * must always open.
 */
class DeckDataResolver
{
    public function __construct(
        private readonly AppManifestService $manifests,
        private readonly RecordQueryService $records,
    ) {}

    /**
     * @param  array<string, mixed>  $deck
     * @return array<string, mixed> the deck with live bindings resolved
     */
    public function resolve(array $deck, User $user): array
    {
        $slides = (array) ($deck['slides'] ?? []);

        foreach ($slides as $i => $slide) {
            if (! is_array($slide)) {
                continue;
            }
            try {
                if (($slide['layout'] ?? null) === 'chart' && is_array($slide['data_source'] ?? null)) {
                    $slides[$i] = $this->resolveChart($slide, $user);
                }
                if (($slide['layout'] ?? null) === 'metrics' && is_array($slide['items'] ?? null)) {
                    $slides[$i]['items'] = array_map(
                        fn ($item) => is_array($item) && is_array($item['value_source'] ?? null)
                            ? $this->resolveMetric($item, $user)
                            : $item,
                        array_values($slide['items']),
                    );
                }
            } catch (\Throwable $e) {
                Log::info('Deck live binding left on static fallback', [
                    'slide' => $i,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [...$deck, 'slides' => array_values($slides)];
    }

    /**
     * @param  array<string, mixed>  $slide
     * @return array<string, mixed>
     */
    private function resolveChart(array $slide, User $user): array
    {
        $source = (array) $slide['data_source'];
        [$app, $manifest, $object] = $this->resolveTarget($source, $user);

        $groups = $this->records->groupedAggregate(
            $app,
            ['object_id' => $object['id']],
            (string) $source['aggregation'],
            $this->fieldId($object, $source['field'] ?? null),
            $this->fieldId($object, $source['group_by'] ?? null) ?? (string) $source['group_by'],
            isset($source['bucket']) ? (string) $source['bucket'] : null,
            $manifest,
            limit: 12,
        );

        if ($groups === []) {
            return $slide;
        }

        $groupField = $this->findField($object, (string) $source['group_by']);
        $optionLabels = collect((array) ($groupField['options'] ?? []))
            ->filter(fn ($o) => is_array($o))
            ->mapWithKeys(fn (array $o) => [(string) ($o['value'] ?? '') => (string) ($o['label'] ?? '')])
            ->all();

        $slide['labels'] = array_map(
            fn (array $g): string => Str::limit($optionLabels[(string) $g['group']] ?? (string) $g['group'], 20, '…'),
            $groups,
        );
        $slide['series'] = [[
            'name' => (string) ($slide['series'][0]['name'] ?? $slide['title'] ?? 'Series'),
            'data' => array_map(fn (array $g) => $g['value'], $groups),
        ]];

        return $slide;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function resolveMetric(array $item, User $user): array
    {
        $source = (array) $item['value_source'];
        [$app, $manifest, $object] = $this->resolveTarget($source, $user);

        $value = $this->records->aggregate(
            $app,
            ['object_id' => $object['id']],
            (string) $source['aggregation'],
            $this->fieldId($object, $source['field'] ?? null),
            $manifest,
        );

        $item['value'] = $this->formatNumber($value);

        return $item;
    }

    /**
     * Resolve {app_slug, object} to the live app, its manifest and the object
     * definition. Throws on any miss — the caller falls back to static content.
     *
     * @param  array<string, mixed>  $source
     * @return array{0: App, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function resolveTarget(array $source, User $user): array
    {
        $app = App::query()
            ->forAccountContext($user)
            ->where('slug', (string) ($source['app_slug'] ?? ''))
            ->firstOrFail();

        $manifest = $this->manifests->getActiveManifest($app);
        if (! is_array($manifest)) {
            throw new \RuntimeException('App has no active manifest.');
        }

        $needle = (string) ($source['object'] ?? '');
        foreach ((array) ($manifest['objects'] ?? []) as $object) {
            if (is_array($object) && (($object['id'] ?? null) === $needle || ($object['slug'] ?? null) === $needle)) {
                return [$app, $manifest, $object];
            }
        }

        throw new \RuntimeException("No object '{$needle}' in app '{$app->slug}'.");
    }

    /**
     * Accept a field id or slug from the manifest author; return the field id
     * (RecordQueryService resolves by id). Null passes through for `count`.
     *
     * @param  array<string, mixed>  $object
     */
    private function fieldId(array $object, mixed $idOrSlug): ?string
    {
        if (! is_string($idOrSlug) || $idOrSlug === '') {
            return null;
        }
        $field = $this->findField($object, $idOrSlug);

        return is_array($field) ? (string) $field['id'] : $idOrSlug;
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>|null
     */
    private function findField(array $object, string $idOrSlug): ?array
    {
        foreach ((array) ($object['fields'] ?? []) as $field) {
            if (is_array($field) && (($field['id'] ?? null) === $idOrSlug || ($field['slug'] ?? null) === $idOrSlug)) {
                return $field;
            }
        }

        return null;
    }

    private function formatNumber(int|float $value): string
    {
        if (is_float($value) && floor($value) !== $value) {
            return number_format($value, 1);
        }

        return number_format((float) $value, 0);
    }
}
