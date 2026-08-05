<?php

namespace App\Services\Apps\Docs;

use App\Models\App;

/**
 * The two documents every app carries: a user guide and a technical sheet.
 *
 * Both are DERIVED from the manifest at read time rather than written once and
 * stored. That is the point. An app changes every time somebody says a sentence
 * to the builder, and a stored document would be wrong by the second change —
 * the state this codebase already knows well from every other cache that sat in
 * front of a source of truth. Deriving them costs a few milliseconds and no
 * model call, so there is nothing to invalidate and nothing to bill.
 *
 * They are written for two different readers. The guide never mentions an id,
 * an object or a block; it names screens, buttons and fields as a person sees
 * them. The technical sheet is the opposite: ids beside every name, because its
 * reader has to change the thing.
 */
class AppDocs
{
    public const KINDS = ['manual', 'technical'];

    /**
     * Both documents for an app, from its current version.
     *
     * @return array{manual: Doc, technical: Doc}
     */
    public function forApp(App $app): array
    {
        $manifest = $this->manifestOf($app);

        return [
            'manual' => $this->manual($manifest),
            'technical' => $this->technical($manifest, $this->runtimeUrl($app)),
        ];
    }

    public function of(App $app, string $kind): Doc
    {
        $manifest = $this->manifestOf($app);

        return $kind === 'technical'
            ? $this->technical($manifest, $this->runtimeUrl($app))
            : $this->manual($manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function manual(array $manifest): Doc
    {
        $reader = new ManifestReader($manifest);

        return (new ManualWriter($reader, DocWords::for($reader->locale())))->write();
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function technical(array $manifest, ?string $runtimeUrl = null): Doc
    {
        $reader = new ManifestReader($manifest);

        return (new TechnicalWriter($reader, DocWords::for($reader->locale()), $runtimeUrl))->write();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestOf(App $app): array
    {
        $manifest = $app->currentVersion?->manifest;

        return is_array($manifest) ? $manifest : [];
    }

    /**
     * The technical sheet for an app, optionally from a manifest that is not
     * the applied one — a builder turn's running DRAFT, so a reader judges what
     * was just authored rather than the version it replaces.
     *
     * @param  array<string, mixed>|null  $manifest
     */
    public function technicalForApp(App $app, ?array $manifest = null): Doc
    {
        return $this->technical($manifest ?? $this->manifestOf($app), $this->runtimeUrl($app));
    }

    private function runtimeUrl(App $app): ?string
    {
        return $app->slug === null ? null : route('apps.runtime', ['app_slug' => $app->slug]);
    }
}
