<?php

namespace App\Services\Records;

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Support\Apps\EnvironmentContext;

/**
 * Whether somebody has already filed a given questionnaire.
 *
 * Asked in two places for the same reason: the runtime asks so it can show
 * "you already answered" instead of a form, and the controller asks so a
 * reload — or a second tab — cannot file twice. A form that renders and then
 * refuses is worse than one that never offered, and a check that lives only in
 * the browser is not a check.
 *
 * The PARTICIPATION MARKER is what this reads, and it is the only thing that
 * can be read. An anonymous submission carries no link to the person by
 * design; the marker is the separate record that says somebody answered
 * without saying what they said. So a questionnaire that declares no marker is
 * undedupable by construction — which is exactly the right shape for a
 * suggestion box, and the escape hatch for anything meant to be filed
 * repeatedly.
 */
class FormParticipation
{
    /**
     * Has this person already filed this questionnaire?
     *
     * False whenever there is nothing to key on: no marker declared, no signed-in
     * person, or the author asked for repeat filing with `once: false`.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $manifest
     */
    public function hasAnswered(App $app, array $block, array $manifest, ?User $user, array $context = []): bool
    {
        $participation = $block['participation'] ?? null;

        if ($participation === null || $user === null || ($participation['once'] ?? true) === false) {
            return false;
        }

        $objectId = (string) ($participation['object_id'] ?? '');
        $personSlug = $this->slugFor($manifest, $objectId, (string) ($participation['person_field_id'] ?? ''));

        if ($objectId === '' || $personSlug === null) {
            return false;
        }

        $query = Record::query()
            ->where('app_id', $app->id)
            ->where('object_definition_id', $objectId)
            // Sandbox and production keep separate books. Without this, testing
            // a survey in the sandbox would lock the tester out of the real one.
            ->where('environment', app(EnvironmentContext::class)->current())
            ->where('data->'.$personSlug, (string) $user->id);

        // Scoped by whatever the author put ON the marker. One participation
        // object usually serves every questionnaire in an app, so a marker that
        // carries {survey: "{{params.survey}}"} must not make answering the
        // climate survey count as answering the onboarding one. Resolved
        // through the same expressions the marker was WRITTEN with, or the
        // comparison would be against the literal "{{params.survey}}".
        foreach ($this->values($participation, $context) as $fieldId => $value) {
            $slug = $this->slugFor($manifest, $objectId, (string) $fieldId);

            if ($slug !== null && is_scalar($value)) {
                $query->where('data->'.$slug, (string) $value);
            }
        }

        return $query->exists();
    }

    /**
     * A spec's `values`, with expressions resolved and keys kept as strings.
     *
     * Shared with the controller so a marker is looked up exactly as it is
     * written. PHP also turns a numeric-looking array key into an int on the
     * way in, and a field id that arrives as 123 matches nothing.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function values(array $spec, array $context): array
    {
        $resolver = app(ExpressionResolver::class);
        $out = [];

        foreach ($spec['values'] ?? [] as $key => $value) {
            $out[(string) $key] = is_string($value) && str_contains($value, '{{')
                ? $resolver->resolve($value, $context)
                : $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function slugFor(array $manifest, string $objectId, string $fieldId): ?string
    {
        foreach ($manifest['objects'] ?? [] as $object) {
            if (($object['id'] ?? null) !== $objectId) {
                continue;
            }

            foreach ($object['fields'] ?? [] as $field) {
                if (($field['id'] ?? null) === $fieldId) {
                    return (string) $field['slug'];
                }
            }
        }

        return null;
    }
}
