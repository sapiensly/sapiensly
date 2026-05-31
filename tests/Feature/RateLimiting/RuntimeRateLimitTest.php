<?php

use App\Jobs\RunBuilderAiJob;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function rlMakeApp(User $user, string $slug, ?string $orgId = null): App
{
    $app = App::factory()->create([
        'user_id' => $user->id,
        'slug' => $slug,
        'visibility' => $orgId ? 'organization' : 'private',
        'organization_id' => $orgId,
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $slug,
        'name' => 'RL',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    return $app;
}

/** A cheap client-side action: echoed back, 200, no workflow. */
function rlAction(): array
{
    return ['actions' => [['type' => 'show_toast', 'message' => 'x', 'level' => 'info']]];
}

it('throttles runtime-actions per user', function () {
    config()->set('security.rate_limits.runtime_actions.per_user', 3);
    config()->set('security.rate_limits.runtime_actions.per_org', 999);

    $user = User::factory()->create(['email_verified_at' => now()]);
    rlMakeApp($user, 'rl_user_app');

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->postJson('/r/rl_user_app/actions', rlAction())->assertOk();
    }

    $this->actingAs($user)->postJson('/r/rl_user_app/actions', rlAction())->assertStatus(429);
});

it('throttles runtime-actions per org across users (under per-user)', function () {
    config()->set('security.rate_limits.runtime_actions.per_user', 50); // not the binding limit
    config()->set('security.rate_limits.runtime_actions.per_org', 3);

    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::random(5)]);
    $u1 = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $org->id]);
    $u2 = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $org->id]);
    rlMakeApp($u1, 'rl_org_app', $org->id);

    $this->actingAs($u1)->postJson('/r/rl_org_app/actions', rlAction())->assertOk();
    $this->actingAs($u1)->postJson('/r/rl_org_app/actions', rlAction())->assertOk();
    $this->actingAs($u2)->postJson('/r/rl_org_app/actions', rlAction())->assertOk();
    // 4th request from the org (even a different user, well under per-user) trips the org cap.
    $this->actingAs($u2)->postJson('/r/rl_org_app/actions', rlAction())->assertStatus(429);
});

it('isolates tenants: one saturated org does not affect another', function () {
    config()->set('security.rate_limits.runtime_actions.per_user', 1);
    config()->set('security.rate_limits.runtime_actions.per_org', 1);

    $orgA = Organization::create(['name' => 'A', 'slug' => 'a-'.Str::random(5)]);
    $orgB = Organization::create(['name' => 'B', 'slug' => 'b-'.Str::random(5)]);
    $a = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $orgA->id]);
    $b = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $orgB->id]);
    rlMakeApp($a, 'rl_a_app', $orgA->id);
    rlMakeApp($b, 'rl_b_app', $orgB->id);

    $this->actingAs($a)->postJson('/r/rl_a_app/actions', rlAction())->assertOk();
    $this->actingAs($a)->postJson('/r/rl_a_app/actions', rlAction())->assertStatus(429); // org A saturated

    // Org B is untouched.
    $this->actingAs($b)->postJson('/r/rl_b_app/actions', rlAction())->assertOk();
});

it('returns a Retry-After header on 429', function () {
    config()->set('security.rate_limits.runtime_actions.per_user', 1);

    $user = User::factory()->create(['email_verified_at' => now()]);
    rlMakeApp($user, 'rl_hdr_app');

    $this->actingAs($user)->postJson('/r/rl_hdr_app/actions', rlAction())->assertOk();
    $this->actingAs($user)->postJson('/r/rl_hdr_app/actions', rlAction())
        ->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('does not enqueue the Claude job when builder-ai is throttled', function () {
    Queue::fake();
    config()->set('security.rate_limits.builder_ai.per_user', 2);
    config()->set('security.rate_limits.builder_ai.per_org', 999);
    config()->set('security.rate_limits.builder_ai.per_org_daily', 999);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);
    $conv = BuilderConversation::create([
        'app_id' => $app->id,
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    $payload = ['conversation_id' => $conv->id, 'message' => 'hi'];

    $this->actingAs($user)->postJson("/apps/{$app->id}/builder/messages", $payload)->assertOk();
    $this->actingAs($user)->postJson("/apps/{$app->id}/builder/messages", $payload)->assertOk();
    // Third is throttled at HTTP admission → the controller never runs → no job.
    $this->actingAs($user)->postJson("/apps/{$app->id}/builder/messages", $payload)->assertStatus(429);

    Queue::assertPushed(RunBuilderAiJob::class, 2);
});

it('enforces the builder-ai daily org quota independent of the per-minute cap', function () {
    Queue::fake();
    config()->set('security.rate_limits.builder_ai.per_user', 999);
    config()->set('security.rate_limits.builder_ai.per_org', 999);
    config()->set('security.rate_limits.builder_ai.per_org_daily', 2);

    $org = Organization::create(['name' => 'Daily', 'slug' => 'daily-'.Str::random(5)]);
    $user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $org->id]);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'organization', 'organization_id' => $org->id]);
    $conv = BuilderConversation::create(['app_id' => $app->id, 'user_id' => $user->id, 'status' => 'active']);
    $payload = ['conversation_id' => $conv->id, 'message' => 'hi'];

    $this->actingAs($user)->postJson("/apps/{$app->id}/builder/messages", $payload)->assertOk();
    $this->actingAs($user)->postJson("/apps/{$app->id}/builder/messages", $payload)->assertOk();
    $this->actingAs($user)->postJson("/apps/{$app->id}/builder/messages", $payload)->assertStatus(429);
});

it('caps a no-org account with the per-user daily AI ceiling', function () {
    Queue::fake();
    // Per-minute is generous; the daily user cap is the binding limit. Without
    // the fallback, a no-org account would have NO daily ceiling at all.
    config()->set('security.rate_limits.builder_ai.per_user', 999);
    config()->set('security.rate_limits.builder_ai.per_org', 999);
    config()->set('security.rate_limits.builder_ai.per_org_daily', 999);
    config()->set('security.rate_limits.builder_ai.per_user_daily', 2);

    // No organization → the org/per-minute org dimension is absent; the daily
    // cap must still apply, keyed by user.
    $user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => null]);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);
    $conv = BuilderConversation::create(['app_id' => $app->id, 'user_id' => $user->id, 'status' => 'active']);
    $payload = ['conversation_id' => $conv->id, 'message' => 'hi'];

    $this->actingAs($user)->postJson("/apps/{$app->id}/builder/messages", $payload)->assertOk();
    $this->actingAs($user)->postJson("/apps/{$app->id}/builder/messages", $payload)->assertOk();
    $this->actingAs($user)->postJson("/apps/{$app->id}/builder/messages", $payload)->assertStatus(429);

    Queue::assertPushed(RunBuilderAiJob::class, 2);
});

it('attaches the throttle middleware to each runtime surface', function () {
    $routes = app('router')->getRoutes();

    expect($routes->getByName('apps.runtime.actions')->gatherMiddleware())
        ->toContain('throttle:runtime-actions')
        ->and($routes->getByName('apps.builder.workflows.run')->gatherMiddleware())
        ->toContain('throttle:builder-workflow-run')
        ->and($routes->getByName('apps.builder.messages')->gatherMiddleware())
        ->toContain('throttle:builder-ai')
        ->and($routes->getByName('apps.builder.visual-review')->gatherMiddleware())
        ->toContain('throttle:builder-ai')
        ->and($routes->getByName('apps.builder.wireframe-import')->gatherMiddleware())
        ->toContain('throttle:builder-ai');
});

it('emits a structured observability event on a limit hit', function () {
    config()->set('security.rate_limits.runtime_actions.per_user', 1);
    Log::spy();

    $user = User::factory()->create(['email_verified_at' => now()]);
    rlMakeApp($user, 'rl_obs_app');

    $this->actingAs($user)->postJson('/r/rl_obs_app/actions', rlAction())->assertOk();
    $this->actingAs($user)->postJson('/r/rl_obs_app/actions', rlAction())->assertStatus(429);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) {
            return $message === 'rate_limit.hit'
                && $context['surface'] === 'runtime-actions'
                && array_key_exists('organization_id', $context);
        })
        ->atLeast()->once();
});
