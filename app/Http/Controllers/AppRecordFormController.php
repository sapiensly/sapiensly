<?php

namespace App\Http\Controllers;

use App\Facades\TenantCache;
use App\Models\App;
use App\Services\Apps\AppAccessResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\FormParticipation;
use App\Services\Records\RecordWriteService;
use App\Support\Tenancy\Schemas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Filing a questionnaire somebody else authored.
 *
 * One submission, N answers, in one transaction. Half a survey is not a smaller
 * survey — it is a response that will be counted and is wrong, because the
 * questions somebody skipped and the questions that failed to save look
 * identical in every chart afterwards.
 *
 * Anonymity is the reason this is a controller and not a sequence of
 * create_record actions. Two things have to be true at once and only the server
 * can hold both: the submission carries NO link to the person, and a separate
 * marker records that they answered. Neither points at the other. An app owner
 * can see who still owes them a response and cannot read what anybody said.
 *
 * The submission's timestamp is coarsened to the DATE when it is anonymous. An
 * exact time re-identifies somebody as surely as a name: a room of thirty
 * people produces thirty distinct minutes, and the participation marker has a
 * timestamp of its own.
 */
class AppRecordFormController extends Controller
{
    /** Answers one submission may carry. A questionnaire, not a data import. */
    private const MAX_ANSWERS = 200;

    public function __construct(
        private readonly AppManifestService $manifests,
        private readonly AppAccessResolver $accessResolver,
        private readonly RecordWriteService $writes,
        private readonly FormParticipation $participation,
    ) {}

    public function __invoke(Request $request, string $appSlug, string $blockId): JsonResponse
    {
        $user = $request->user();

        $app = App::query()->forAccountContext($user)->where('slug', $appSlug)->first();
        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        $manifest = $this->manifests->getActiveManifest($app);
        if ($manifest === null) {
            throw new NotFoundHttpException("App '{$appSlug}' has no published manifest.");
        }

        $data = $request->validate([
            'answers' => ['required', 'array', 'min:1', 'max:'.self::MAX_ANSWERS],
            'answers.*.question_id' => ['required', 'string', 'regex:/^rec_[a-z0-9]+$/'],
            'answers.*.kind' => ['required', 'string', 'max:40'],
            'answers.*.value' => ['nullable'],
        ]);

        // The BLOCK is the contract. Everything about where answers go, which
        // column each kind lands in and whether this is anonymous comes from
        // the manifest — never from the browser, which would otherwise be
        // naming the object it wants to write to.
        $block = $this->findBlock($manifest, $blockId);
        if ($block === null) {
            throw new NotFoundHttpException('No such form here.');
        }

        $answersSpec = $block['answers'];
        $previewRole = (string) $request->query('as_role', '');
        $access = $this->accessResolver->resolve($app, $manifest, $user, $previewRole !== '' ? $previewRole : null);

        abort_unless($access->hasAccess && $access->can((string) $answersSpec['object_id'], 'create'), 403);

        $submissionSpec = $block['submission'] ?? null;
        $anonymous = ($submissionSpec['anonymous'] ?? false) === true;

        // What `submission.values` and `participation.values` are resolved
        // against. This is what lets ONE app hold several questionnaires: the
        // marker carries {{params.survey}} off the URL, so answering one is not
        // answering another.
        $context = [
            'params' => array_filter($request->query(), static fn ($v): bool => is_string($v) || is_array($v)),
            'current_user' => $user === null ? [] : ['id' => $user->id, 'email' => $user->email],
        ];

        // Two filings at once — a double-click, or two tabs. Put-if-absent is
        // atomic, so exactly one of them gets past here; the loser is told the
        // same thing a reload is told. The `once` check below cannot do this on
        // its own because both requests would read "not answered yet" before
        // either had written its marker.
        $gate = 'form_filing:'.$app->id.':'.$blockId.':'.($user?->id ?? 'anon');
        $held = $user !== null && TenantCache::add($gate, true, 30);

        if ($user !== null && ! $held) {
            return $this->alreadyAnswered();
        }

        try {
            if ($this->participation->hasAnswered($app, $block, $manifest, $user, $context)) {
                return $this->alreadyAnswered();
            }

            // A roster questionnaire is by invitation. Refused BEFORE anything
            // is written, and loudly: filing the answers while quietly skipping
            // the marker would let this person answer again tomorrow, and would
            // leave them missing from the list of who still owes a response.
            $roster = $block['participation']['person_lookup'] ?? null;

            if ($roster !== null && $user !== null
                && $this->participation->personValue($app, $block['participation'], $manifest, $user) === null) {
                return response()->json(['error' => 'not_invited'], 403);
            }

            $written = DB::connection(Schemas::connectionFor('records'))->transaction(
                fn (): int => $this->file($app, $manifest, $block, $data['answers'], $user, $anonymous, $context),
            );
        } finally {
            // Released rather than left to expire: a filing that failed should
            // be retryable now, not in thirty seconds.
            if ($held) {
                TenantCache::forget($gate);
            }
        }

        return response()->json(['answers' => $written, 'anonymous' => $anonymous]);
    }

    /**
     * Refused because this person already filed it.
     *
     * 409 rather than 422: nothing about the answers is wrong, and on an
     * anonymous questionnaire a duplicate is not merely untidy — there is no
     * way afterwards to tell which of the two filings was theirs, so it cannot
     * even be cleaned up later.
     */
    private function alreadyAnswered(): JsonResponse
    {
        return response()->json(['error' => 'already_answered'], 409);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $block
     * @param  list<array<string, mixed>>  $answers
     */
    private function file(App $app, array $manifest, array $block, array $answers, mixed $user, bool $anonymous, array $context): int
    {
        $answersSpec = $block['answers'];
        $submissionSpec = $block['submission'] ?? null;
        $submissionId = null;

        if ($submissionSpec !== null) {
            // current_user is withheld from an ANONYMOUS submission's own
            // values: {{current_user.id}} there would write the very name this
            // design exists to leave out, and it would look deliberate.
            $values = $this->participation->values(
                $submissionSpec,
                $anonymous ? ['params' => $context['params']] : $context,
            );

            // Stamped only where the author said to. It used to go into a
            // hardcoded `completado_en`, which meant the write path silently
            // dropped it for every app that did not happen to name the field
            // in Spanish — RecordWriteService builds its row from the object's
            // DECLARED fields and discards anything else.
            $stampField = $submissionSpec['completed_field_id'] ?? null;

            if ($stampField !== null) {
                // Coarsened on purpose when anonymous — see the class docblock.
                $values[(string) $stampField] = $anonymous
                    ? now()->toDateString()
                    : now()->toIso8601String();
            }

            $submission = $this->writes->create(
                $app,
                $manifest,
                (string) $submissionSpec['object_id'],
                $values,
                // No actor on an anonymous submission: created_by_user_id would
                // be the name this whole design exists to leave out.
                $anonymous ? null : $user,
            );

            $submissionId = $submission->id;
        }

        $written = 0;

        foreach ($answers as $answer) {
            $column = $answersSpec['value_field_ids'][$answer['kind']] ?? null;
            if ($column === null || $answer['value'] === null || $answer['value'] === '') {
                continue;
            }

            $values = [
                $answersSpec['question_field_id'] => $answer['question_id'],
                $column => $answer['value'],
            ];

            if ($submissionId !== null && isset($answersSpec['parent_field_id'])) {
                $values[$answersSpec['parent_field_id']] = $submissionId;
            }

            $this->writes->create(
                $app,
                $manifest,
                (string) $answersSpec['object_id'],
                $values,
                $anonymous ? null : $user,
            );

            $written++;
        }

        // Who answered, in a record that points at nothing they said. This is
        // the half that lets somebody be reminded without being read.
        $participation = $block['participation'] ?? null;
        if ($participation !== null && $user !== null) {
            $person = $this->participation->personValue($app, $participation, $manifest, $user);

            // Null only when the questionnaire goes to a ROSTER and this person
            // is not on it. Checked before anything is written — see __invoke.
            if ($person !== null) {
                $this->writes->create(
                    $app,
                    $manifest,
                    (string) $participation['object_id'],
                    array_merge(
                        $this->participation->values($participation, $context),
                        [$participation['person_field_id'] => $person],
                    ),
                    $user,
                );
            }
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function findBlock(array $manifest, string $blockId): ?array
    {
        foreach ($manifest['pages'] ?? [] as $page) {
            $found = $this->searchBlocks($page['blocks'] ?? [], $blockId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>|null
     */
    private function searchBlocks(array $blocks, string $blockId): ?array
    {
        foreach ($blocks as $block) {
            if (($block['id'] ?? null) === $blockId && ($block['type'] ?? null) === 'record_form') {
                return $block;
            }

            if (is_array($block['blocks'] ?? null)) {
                $found = $this->searchBlocks($block['blocks'], $blockId);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
