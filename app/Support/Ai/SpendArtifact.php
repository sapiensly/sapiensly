<?php

namespace App\Support\Ai;

use App\Enums\DocumentType;
use App\Models\Agent;
use App\Models\App;
use App\Models\Channel;
use App\Models\Chat;
use App\Models\Chatbot;
use App\Models\Debate;
use App\Models\Document;
use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a spend subject maps to a thing a person can name.
 *
 * The ledger stores a short slug rather than a class name ({@see the
 * add_artifact_subject migration}) so an append-only row survives a class being
 * moved; this registry is the translation, in both directions:
 *   - writing: a model handed to {@see App\Services\Ai\AiUsageRecorder} becomes
 *     (slug, id);
 *   - reading: a batch of (slug, id) pairs becomes names + kind labels for the
 *     dashboard, in one query per slug rather than one per row.
 *
 * A subject whose row is gone resolves to a null name. The dashboard shows the
 * bare id in that case — spend that outlived its artifact is still spend, and
 * hiding it would make the service totals stop adding up.
 */
final class SpendArtifact
{
    /**
     * slug => [model, the column holding its display name, kind label].
     *
     * @var array<string, array{0: class-string<Model>, 1: string, 2: string}>
     */
    private const TYPES = [
        'app' => [App::class, 'name', 'App'],
        'agent' => [Agent::class, 'name', 'Agent'],
        'chat' => [Chat::class, 'title', 'Chat'],
        'chatbot' => [Chatbot::class, 'name', 'Chatbot'],
        'channel' => [Channel::class, 'name', 'Channel'],
        'debate' => [Debate::class, 'title', 'Debate'],
        'document' => [Document::class, 'name', 'Document'],
        'knowledge_base' => [KnowledgeBase::class, 'name', 'Knowledge base'],
    ];

    /**
     * The (slug, id) pair to store for a subject, or null for a model this
     * registry does not know — an unknown subject records as unattributed
     * rather than as a slug nothing can resolve.
     *
     * @return array{subject_type: string, subject_id: string}|null
     */
    public static function of(?Model $subject): ?array
    {
        if ($subject === null) {
            return null;
        }

        $type = self::typeFor($subject);
        $id = $subject->getKey();

        if ($type === null || $id === null) {
            return null;
        }

        return ['subject_type' => $type, 'subject_id' => (string) $id];
    }

    public static function typeFor(Model $subject): ?string
    {
        foreach (self::TYPES as $slug => [$class]) {
            if ($subject instanceof $class) {
                return $slug;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function kindFor(string $type): ?string
    {
        return self::TYPES[$type][2] ?? null;
    }

    /**
     * Resolve many subjects at once — one query per type, not one per row.
     *
     * @param  iterable<array{0: string, 1: string}>  $pairs  (type, id)
     * @return array<string, array{name: string|null, kind: string}> keyed "type:id"
     */
    public static function resolve(iterable $pairs): array
    {
        $byType = [];
        foreach ($pairs as [$type, $id]) {
            if (isset(self::TYPES[$type]) && $id !== '') {
                $byType[$type][$id] = true;
            }
        }

        $resolved = [];

        foreach ($byType as $type => $ids) {
            [$class, $nameColumn, $kind] = self::TYPES[$type];

            /** @var Model $model */
            $model = new $class;
            // Without global scopes on purpose, for two reasons: the report sums
            // every event in the organization, so the owner reading it must be
            // able to name all of them (a member's private chat would otherwise
            // show as a bare id), and a soft-deleted artifact still explains the
            // spend it caused. RLS still bounds this to the org at the database
            // layer, which is the boundary that matters.
            $query = $model->newQuery()
                ->withoutGlobalScopes()
                ->whereIn($model->getKeyName(), array_keys($ids));

            $columns = [$model->getKeyName(), $nameColumn];
            if ($type === 'document') {
                $columns[] = 'type'; // a deck and an uploaded PDF are both Documents
            }

            foreach ($query->get($columns) as $row) {
                $resolved[$type.':'.$row->getKey()] = [
                    'name' => self::stringOrNull($row->{$nameColumn}),
                    'kind' => $type === 'document' ? self::documentKind($row) : $kind,
                ];
            }
        }

        return $resolved;
    }

    /** A slide deck and an uploaded PDF are both Documents; say which. */
    private static function documentKind(Model $document): string
    {
        $type = $document->type;

        return match (true) {
            $type instanceof DocumentType => $type->label(),
            is_string($type) && $type !== '' => ucfirst($type),
            default => 'Document',
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
