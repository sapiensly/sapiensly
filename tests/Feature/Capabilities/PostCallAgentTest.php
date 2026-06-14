<?php

use App\Ai\ChatAgent;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Capabilities\PostCall\ApplyCrmUpdate;
use App\Services\Capabilities\PostCall\Connectors\FakeHubSpotConnector;
use App\Services\Capabilities\PostCall\Contracts\CrmConnector;
use App\Services\Capabilities\PostCall\DraftCrmUpdate;
use App\Services\Capabilities\PostCall\FetchCallContext;
use App\Services\Capabilities\PostCall\PostCallAgent;
use Laravel\Ai\Ai;

/**
 * Behavioral verification of capability #0001 against the in-memory fake CRM
 * (the dry-run sandbox), per the acceptance criteria in
 * docs/capabilities/0001-hubspot-post-call-agent.md §6.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);

    /** @var FakeHubSpotConnector $crm */
    $this->crm = app(CrmConnector::class);
    $this->crm->seedObject('deal', 'deal_1', ['dealstage' => 'qualification']);
    $this->crm->seedCall('call_1', [
        'direction' => 'outbound',
        'transcript' => 'Customer confirmed the budget and agreed to sign this week.',
        'associations' => ['deal_id' => 'deal_1'],
    ]);
});

function fakeDraft(string $json): void
{
    Ai::fakeAgent(ChatAgent::class, [$json]);
}

$winProposal = '{"target":{"object_type":"deal","object_id":"deal_1"},"operation":"update","changes":[{"field":"dealstage","to":"closedwon"}],"rationale":"Customer agreed to sign","confidence":0.9,"evidence":[{"quote":"agreed to sign this week"}]}';

it('reads a call snapshot (partial-tolerant)', function () {
    $context = app(FetchCallContext::class)->fetch('call_1');

    expect($context->callId)->toBe('call_1')
        ->and($context->transcript)->not->toBeNull()
        ->and($context->sourceFetchedAt)->not->toBeNull();
});

it('drafts a proposal grounded in the call without touching the CRM', function () use ($winProposal) {
    fakeDraft($winProposal);

    $proposal = app(PostCallAgent::class)->run('call_1', $this->user);

    // Proposal matches the call's intent, with from/to filled from real CRM state.
    expect($proposal->status)->toBe('pending')
        ->and($proposal->target['object_type'])->toBe('deal')
        ->and($proposal->operation)->toBe('update')
        ->and($proposal->changes[0]['field'])->toBe('dealstage')
        ->and($proposal->changes[0]['from'])->toBe('qualification')
        ->and($proposal->changes[0]['to'])->toBe('closedwon')
        ->and($proposal->rationale)->not->toBe('')
        ->and($proposal->confidence)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0)
        ->and($proposal->evidence)->not->toBeEmpty();

    // Propose-don't-mutate: nothing was written to the system of record.
    expect($this->crm->appliedCount())->toBe(0)
        ->and($this->crm->writeLog)->toBeEmpty();
});

it('applies an approved proposal only through the gate', function () use ($winProposal) {
    fakeDraft($winProposal);
    $proposal = app(PostCallAgent::class)->run('call_1', $this->user);

    $applied = app(ApplyCrmUpdate::class)->apply($proposal, $this->user);

    expect($applied->status)->toBe('applied')
        ->and($applied->external_object_id)->toBe('deal_1')
        ->and($applied->approver_id)->toBe($this->user->id)
        ->and($applied->applied_at)->not->toBeNull()
        ->and($this->crm->appliedCount())->toBe(1);
});

it('is idempotent on re-apply', function () use ($winProposal) {
    fakeDraft($winProposal);
    $proposal = app(PostCallAgent::class)->run('call_1', $this->user);

    $gate = app(ApplyCrmUpdate::class);
    $gate->apply($proposal, $this->user);
    $gate->apply($proposal->fresh(), $this->user);

    // Two apply calls, but the system of record was mutated once.
    expect($this->crm->appliedCount())->toBe(1);
});

it('degrades without a transcript: low-confidence, never auto-applied', function () {
    $this->crm->seedCall('call_2', ['associations' => ['deal_id' => 'deal_1']]); // no transcript
    fakeDraft('{"operation":"none","confidence":0,"rationale":"No usable signal","changes":[],"target":null}');

    $proposal = app(PostCallAgent::class)->run('call_2', $this->user);

    expect($proposal->status)->toBe('pending')
        ->and($proposal->confidence)->toBe(0.0)
        ->and(DraftCrmUpdate::isActionable($proposal))->toBeFalse()
        // No write happened — drafting never applies, regardless of confidence.
        ->and($this->crm->appliedCount())->toBe(0);
});
