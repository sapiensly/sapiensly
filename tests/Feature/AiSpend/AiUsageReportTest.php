<?php

use App\Models\AiCatalogModel;
use App\Models\AiUsageEvent;
use App\Models\App;
use App\Models\Chat;
use App\Models\Organization;
use App\Models\SystemAiUsageEvent;
use App\Models\User;
use App\Services\Ai\AiUsageReport;
use App\Support\Ai\SpendPeriod;

/**
 * Org-level AI spend, phase 1 — the dashboard read model. Shapes recorded events
 * into totals, own/system split, per-model and a daily series; the platform view
 * additionally breaks spend down by organization.
 */
function spendEvent(array $attrs = []): AiUsageEvent
{
    return AiUsageEvent::create(array_merge([
        'organization_id' => 'org_aaaaaaaaaaaa',
        'user_id' => null,
        'module' => 'chat',
        'driver' => 'anthropic',
        'model' => 'claude-test',
        'source' => 'system',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 1.0,
        'estimated' => false,
        'status' => 'success',
    ], $attrs));
}

it('shapes a current-scope report with totals, source split and series', function () {
    spendEvent(['source' => 'system', 'cost' => 1.0, 'model' => 'claude-a']);
    spendEvent(['source' => 'own', 'cost' => 0.5, 'model' => 'claude-b']);

    $r = app(AiUsageReport::class)->forCurrentOrg(30);

    expect($r['totals']['cost'])->toBe(1.5)
        ->and($r['by_source']['system'])->toBe(1.0)
        ->and($r['by_source']['own'])->toBe(0.5)
        ->and($r['by_model'])->toHaveCount(2)
        ->and($r['series']['labels'])->toHaveCount(30)
        ->and($r['series']['labels'])->toHaveCount(count($r['series']['system']));
});

it('breaks current-scope spend down by service, each with a per-model split', function () {
    // Chat: two models. Apps: builder + runtime_agent roll up into one service.
    spendEvent(['module' => 'chat', 'model' => 'claude-a', 'cost' => 1.0]);
    spendEvent(['module' => 'chat', 'model' => 'claude-b', 'cost' => 0.5]);
    spendEvent(['module' => 'builder', 'model' => 'claude-a', 'cost' => 2.0]);
    spendEvent(['module' => 'runtime_agent', 'model' => 'claude-a', 'cost' => 1.0]);

    $r = app(AiUsageReport::class)->forCurrentOrg(30);

    $services = collect($r['by_service'])->keyBy('service');

    // Apps (3.0) outranks Chat (1.5) and is listed first.
    expect(collect($r['by_service'])->pluck('service')->all())->toBe(['Apps', 'Chat'])
        ->and($services['Apps']['cost'])->toBe(3.0)
        ->and($services['Apps']['calls'])->toBe(2)
        ->and($services['Apps']['models'])->toHaveCount(1)
        ->and($services['Chat']['cost'])->toBe(1.5)
        ->and($services['Chat']['models'])->toHaveCount(2)
        ->and(collect($services['Chat']['models'])->firstWhere('model', 'claude-a')['cost'])->toBe(1.0);
});

it('flags models that have usage but no catalog price as unpriced', function () {
    AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => 'claude-priced',
        'label' => 'Claude Priced',
        'capability' => 'chat',
        'input_price_per_mtok' => 3.0,
        'output_price_per_mtok' => 15.0,
        'is_enabled' => true,
        'sort_order' => 0,
    ]);
    spendEvent(['model' => 'claude-priced', 'cost' => 1.0]);
    spendEvent(['model' => 'claude-mystery', 'cost' => 0.0, 'input_tokens' => 500000]);

    $r = app(AiUsageReport::class)->forCurrentOrg(30);

    $models = collect($r['by_model'])->keyBy('model');
    expect($models['claude-mystery']['unpriced'])->toBeTrue()
        ->and($models['claude-priced'])->not->toHaveKey('unpriced');
});

it('groups by the artifact first, then by the services it used', function () {
    $user = User::factory()->create();
    $app = App::factory()->create(['name' => 'Order Desk']);
    $chat = Chat::create(['user_id' => $user->id, 'title' => 'Pricing questions']);

    // App-shaped spend names itself through app_id; everything else through the
    // polymorphic subject. One app, two services — the question "what did this
    // app cost me" must be answerable in one row, not split across two cards.
    spendEvent(['module' => 'builder', 'cost' => 2.0, 'app_id' => $app->id]);
    spendEvent(['module' => 'landing_director', 'cost' => 0.5, 'app_id' => $app->id]);
    spendEvent(['module' => 'chat', 'cost' => 1.0, 'subject_type' => 'chat', 'subject_id' => $chat->id]);
    spendEvent(['module' => 'chat', 'cost' => 0.25, 'subject_type' => 'chat', 'subject_id' => $chat->id]);

    $artifacts = collect(app(AiUsageReport::class)->forCurrentOrg(30)['by_artifact']);

    expect($artifacts)->toHaveCount(2);

    $orderDesk = $artifacts->firstWhere('id', $app->id);
    expect($orderDesk)->toMatchArray([
        'name' => 'Order Desk', 'kind' => 'App', 'type' => 'app', 'cost' => 2.5, 'calls' => 2,
    ])
        ->and(collect($orderDesk['services'])->pluck('cost', 'service')->all())
        ->toBe(['Apps' => 2.0, 'Landing Director' => 0.5]);

    // Two calls on one chat roll up into a single row, and the biggest spender
    // is listed first.
    expect($artifacts->first()['id'])->toBe($app->id)
        ->and($artifacts->last())->toMatchArray(['name' => 'Pricing questions', 'cost' => 1.25, 'calls' => 2]);
});

it('keeps untagged spend on its own line instead of dropping it', function () {
    spendEvent(['module' => 'debate', 'cost' => 3.0]); // no app_id, no subject

    $r = app(AiUsageReport::class)->forCurrentOrg(30);

    // The totals have to keep adding up, so the row survives — unnamed.
    expect($r['by_artifact'])->toHaveCount(1)
        ->and($r['by_artifact'][0])->toMatchArray([
            'name' => null, 'kind' => null, 'type' => null, 'id' => null, 'cost' => 3.0, 'calls' => 1,
        ])
        ->and($r['by_artifact'][0]['services'])->toBe([[
            'service' => 'Debate', 'cost' => 3.0, 'calls' => 1, 'input_tokens' => 1000, 'output_tokens' => 500,
        ]]);
});

it('names an artifact that no longer exists by its bare id', function () {
    spendEvent(['module' => 'chat', 'cost' => 1.0, 'subject_type' => 'chat', 'subject_id' => 'chat_gone']);

    expect(app(AiUsageReport::class)->forCurrentOrg(30)['by_artifact'][0])
        ->toMatchArray(['name' => null, 'kind' => 'Chat', 'type' => 'chat', 'id' => 'chat_gone', 'cost' => 1.0]);
});

it('gives every module in use a deliberate service name', function () {
    // The fallback renders the raw internal slug, which is how `express`,
    // `chatbot` and `whatsapp` once surfaced as service names in the UI.
    foreach (['express', 'chatbot', 'whatsapp'] as $module) {
        spendEvent(['module' => $module, 'cost' => 1.0]);
    }

    $names = collect(app(AiUsageReport::class)->forCurrentOrg(30)['by_service'])->pluck('service');

    expect($names)->toContain('Apps', 'Chatbots', 'WhatsApp')
        ->and($names)->not->toContain('Express', 'Chatbot', 'Whatsapp');
});

it('windows a calendar period to its boundary, not N days back', function () {
    // On the 3rd, "this month" is three days — so the 30th of last month is out
    // even though a rolling 30-day window would include it.
    $this->travelTo('2026-06-30 12:00:00');
    spendEvent(['cost' => 1.0]);
    $this->travelTo('2026-07-02 12:00:00');
    spendEvent(['cost' => 2.0]);
    $this->travelTo('2026-07-03 12:00:00');

    $month = app(AiUsageReport::class)->forCurrentOrg(SpendPeriod::fromKey('month'));
    $rolling = app(AiUsageReport::class)->forCurrentOrg(SpendPeriod::fromKey('30d'));

    expect($month['totals']['cost'])->toBe(2.0)
        ->and($month['range_days'])->toBe(3)
        ->and($month['series']['labels'])->toHaveCount(3)
        ->and($month['period']['key'])->toBe('month')
        // The same events over a rolling 30 days do include June.
        ->and($rolling['totals']['cost'])->toBe(3.0);
});

it('buckets today by the hour', function () {
    // Yesterday must not leak into a today window.
    $this->travelTo('2026-07-14 09:20:00');
    spendEvent(['cost' => 9.0]);
    $this->travelTo('2026-07-15 09:20:00');
    spendEvent(['cost' => 1.0]);
    $this->travelTo('2026-07-15 09:45:00');
    spendEvent(['cost' => 0.5]);
    $this->travelTo('2026-07-15 15:00:00');

    $r = app(AiUsageReport::class)->forCurrentOrg(SpendPeriod::fromKey('today'));

    expect($r['totals']['cost'])->toBe(1.5)
        ->and($r['period']['granularity'])->toBe('hour')
        ->and($r['series']['labels'])->toHaveCount(24)
        // Both events land in the 09:00 bucket, summed.
        ->and($r['series']['system'][9])->toBe(1.5)
        ->and($r['series']['system'][8])->toBe(0.0);
});

it('leaves the artifact grouping off the cross-org views', function () {
    // Names resolve through the tenant models, which are RLS-scoped to the
    // caller — a sysadmin reading another org would get blanks, so the section
    // is absent rather than empty.
    systemLedgerEvent(['cost' => 1.0]);

    expect(app(AiUsageReport::class)->platformWide(30))->not->toHaveKey('by_artifact')
        ->and(app(AiUsageReport::class)->forOrganization('org_aaaaaaaaaaaa', 30))->not->toHaveKey('by_artifact')
        ->and(app(AiUsageReport::class)->forCurrentOrg(30))->toHaveKey('by_artifact');
});

it('windows the platform-wide and single-org views by period too', function () {
    $this->travelTo('2026-07-01 08:00:00');
    systemLedgerEvent(['cost' => 7.0]);
    $this->travelTo('2026-07-15 08:00:00');
    systemLedgerEvent(['cost' => 4.0]);
    $this->travelTo('2026-07-15 15:00:00');

    $today = app(AiUsageReport::class)->platformWide(SpendPeriod::fromKey('today'));
    $org = app(AiUsageReport::class)->forOrganization('org_aaaaaaaaaaaa', SpendPeriod::fromKey('today'));

    expect($today['totals']['cost'])->toBe(4.0)
        ->and($today['series']['system'][8])->toBe(4.0)
        ->and($org['totals']['cost'])->toBe(4.0);
});

function systemLedgerEvent(array $attrs = []): SystemAiUsageEvent
{
    return SystemAiUsageEvent::create(array_merge([
        'organization_id' => 'org_aaaaaaaaaaaa',
        'user_id' => null,
        'module' => 'chat',
        'driver' => 'anthropic',
        'model' => 'claude-test',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => 1.0,
        'estimated' => false,
        'status' => 'success',
    ], $attrs));
}

it('breaks platform-wide spend down by organization from the system ledger', function () {
    systemLedgerEvent(['organization_id' => 'org_aaaaaaaaaaaa', 'cost' => 2.0]);
    systemLedgerEvent(['organization_id' => 'org_bbbbbbbbbbbb', 'cost' => 3.0]);

    $r = app(AiUsageReport::class)->platformWide(30);

    expect($r['totals']['cost'])->toBe(5.0)
        ->and($r['by_source']['system'])->toBe(5.0)
        ->and(collect($r['by_org'])->pluck('organization_id'))
        ->toContain('org_aaaaaaaaaaaa', 'org_bbbbbbbbbbbb');
});

it('merges own (tenant) and system (platform ledger) spend platform-wide', function () {
    // `own` BYOK spend comes from the tenant table; `system` from the ledger.
    spendEvent(['source' => 'own', 'cost' => 0.5]);
    systemLedgerEvent(['cost' => 2.0]);

    $r = app(AiUsageReport::class)->platformWide(30);

    expect($r['by_source']['own'])->toBe(0.5)
        ->and($r['by_source']['system'])->toBe(2.0)
        ->and($r['totals']['cost'])->toBe(2.5);
});

it('counts an unattributed system call in the platform-wide total', function () {
    systemLedgerEvent(['organization_id' => null, 'cost' => 1.5]);

    $r = app(AiUsageReport::class)->platformWide(30);

    expect($r['by_source']['system'])->toBe(1.5)
        ->and(collect($r['by_org'])->firstWhere('organization_id', null)['cost'])->toBe(1.5);
});

it('labels platform-wide org rows with the organization name', function () {
    $org = Organization::create(['name' => 'Acme Co', 'slug' => 'acme-'.uniqid()]);
    systemLedgerEvent(['organization_id' => $org->id, 'cost' => 2.0]);

    $r = app(AiUsageReport::class)->platformWide(30);

    expect(collect($r['by_org'])->firstWhere('organization_id', $org->id)['name'])->toBe('Acme Co');
});

it('scopes a single-org drill-down to that org with a per-service split', function () {
    $org = Organization::create(['name' => 'Solo', 'slug' => 'solo-'.uniqid()]);
    spendEvent(['organization_id' => $org->id, 'source' => 'own', 'module' => 'chat', 'cost' => 1.0]);
    // A second org's spend must NOT leak into the drill-down.
    spendEvent(['organization_id' => 'org_other0000', 'source' => 'own', 'module' => 'chat', 'cost' => 5.0]);

    $r = app(AiUsageReport::class)->forOrganization($org->id, 30);

    expect($r['totals']['cost'])->toBe(1.0)
        ->and(collect($r['by_service'])->pluck('service')->all())->toBe(['Chat']);
});

/**
 * The per-build report and the org-wide one read the SAME ledger rows, so a
 * disagreement about one app is always the window, never the money. Their
 * defaults differ (90 days vs 30), which is exactly how a build read as $0.0143
 * next to $0.1661 for the same app — the gap being one turn from the day before.
 */
it('agrees with the org-wide artifact line for the same app and window', function () {
    $app = App::factory()->create(['name' => 'Order Desk']);

    spendEvent(['module' => 'builder', 'cost' => 2.0, 'app_id' => $app->id]);
    spendEvent(['module' => 'landing_director', 'cost' => 0.5, 'app_id' => $app->id]);
    // Another app's spend must not bleed into either reading.
    spendEvent(['module' => 'builder', 'cost' => 7.0, 'app_id' => App::factory()->create()->id]);

    $build = app(AiUsageReport::class)->forApp($app->id, days: 30);
    $line = collect(app(AiUsageReport::class)->forCurrentOrg(30)['by_artifact'])
        ->firstWhere('id', $app->id);

    expect($build['totals']['cost'])->toBe($line['cost'])
        ->and($build['totals']['calls'])->toBe($line['calls'])
        ->and($build['totals']['cost'])->toBe(2.5);
});

it('states the dates a build total actually covers, not just the day count', function () {
    $app = App::factory()->create();

    spendEvent(['module' => 'builder', 'cost' => 1.0, 'app_id' => $app->id]);
    // Older than the window: legitimately absent from a 30-day read, and the
    // reason a 90-day read of the same app returns more. Backdated after the
    // insert — created_at is not fillable, so passing it to create() is silently
    // ignored and the row lands today.
    spendEvent(['module' => 'builder', 'cost' => 4.0, 'app_id' => $app->id])
        ->forceFill(['created_at' => now()->subDays(45)])->save();

    $short = app(AiUsageReport::class)->forApp($app->id, days: 30);
    $long = app(AiUsageReport::class)->forApp($app->id, days: 90);

    expect($short['totals']['cost'])->toBe(1.0)
        ->and($long['totals']['cost'])->toBe(5.0)
        // Without this a reader has two totals and no way to see why they differ.
        ->and($short['window']['from'])->toBe(today()->subDays(29)->toDateString())
        ->and($short['window']['to'])->toBe(today()->toDateString())
        ->and($long['window']['from'])->toBe(today()->subDays(89)->toDateString());
});
