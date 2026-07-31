<?php

use App\Models\AiCatalogModel;
use App\Models\Chatbot;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Models\WidgetSession;
use App\Services\Ai\AiDefaults;
use App\Support\Tenancy\Schemas;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * The admin overview reports measurements, not seeded fiction. The point of
 * these tests is that a number on the screen can be traced to a row, and that a
 * section with nothing behind it comes back null rather than plausible.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg('Acme');
    $this->sysadmin = mcpSysadmin($this->org);
});

function seedConversation(string $organizationId, bool $resolved, int $responseMs, ?string $at = null): void
{
    $chatbot = Chatbot::factory()->create(['organization_id' => $organizationId]);
    $session = WidgetSession::create([
        'chatbot_id' => $chatbot->id,
        'session_token' => (string) Str::ulid(),
        'organization_id' => $organizationId,
    ]);

    DB::connection('pgsql')->table(Schemas::qualify('widget_conversations'))->insert([
        // widget_conversations.id is a bare char(26) ULID, not a prefixed id.
        'id' => (string) Str::ulid(),
        'chatbot_id' => $chatbot->id,
        'widget_session_id' => $session->id,
        'organization_id' => $organizationId,
        'message_count' => 4,
        'is_resolved' => $resolved,
        'is_abandoned' => false,
        'total_response_time_ms' => $responseMs,
        'created_at' => $at ?? now()->subHour(),
        'updated_at' => $at ?? now()->subHour(),
    ]);
}

it('counts conversations from every organization, not just the viewer\'s', function () {
    $other = mcpOrg('Globex');

    seedConversation($this->org->id, resolved: true, responseMs: 2000);
    seedConversation($other->id, resolved: true, responseMs: 4000);
    seedConversation($other->id, resolved: false, responseMs: 6000);

    $this->actingAs($this->sysadmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.ticketsResolved.value', 2)
            ->where('layers.understand.count', 3)
            ->where('layers.resolve.count', 2)
            // (2000 + 4000 + 6000) / 3 = 4000ms
            ->where('stats.avgHandleTime.value', 4)
            ->where('stats.avgHandleTime.display', '4.0s')
        );
});

it('reports real account totals', function () {
    User::factory()->count(3)->create();

    $this->actingAs($this->sysadmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.totalUsers.value', User::query()->count())
        );
});

it('returns null for the layer panel when nothing ran in the window', function () {
    $this->actingAs($this->sysadmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('layers', null)
            ->where('spend', null)
        );
});

it('groups the last day of spend by provider', function () {
    foreach ([['anthropic', 0.40], ['anthropic', 0.10], ['openai', 0.05]] as [$driver, $cost]) {
        DB::connection('pgsql')->table('platform.system_ai_usage_events')->insert([
            'organization_id' => $this->org->id,
            'module' => 'chat',
            'driver' => $driver,
            'model' => 'test-model',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cost' => $cost,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
    }

    $this->actingAs($this->sysadmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Ordered by cost, so Anthropic leads.
            ->where('spend.providers.0.name', 'Anthropic')
            ->where('spend.providers.0.calls', 2)
            ->where('spend.providers.0.cost', 0.5)
            ->where('spend.providers.1.calls', 1)
            ->where('stats.tokensUsed.value', 450)
        );
});

it('probes health instead of asserting it', function () {
    $this->actingAs($this->sysadmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $health = collect($page->toArray()['props']['health']);

            expect($health->pluck('id')->all())
                ->toContain('llm', 'embeddings', 'db', 'redis', 'queue', 'reverb');

            // Postgres is genuinely up under the test suite, so it must say so.
            expect($health->firstWhere('id', 'db')['status'])->toBe('ok');
        });
});

it('flags a default model whose provider has no key', function () {
    $model = AiCatalogModel::create([
        'driver' => 'deepseek',
        'model_id' => 'unreachable-model',
        'label' => 'Unreachable',
        'capability' => 'chat',
        'is_enabled' => true,
    ]);

    config(['ai.providers.deepseek.key' => '']);
    app(AiDefaults::class)->setCatalogId('chat', 'primary', (string) $model->id);

    $this->actingAs($this->sysadmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $llm = collect($page->toArray()['props']['health'])->firstWhere('id', 'llm');

            expect($llm['status'])->toBe('error')
                ->and($llm['detail'])->toContain('unreachable-model');
        });
});

it('feeds the activity list from the audit log, reading as a sentence', function () {
    PlatformAuditLog::create([
        'actor_user_id' => $this->sysadmin->id,
        'actor_email' => $this->sysadmin->email,
        'action' => 'manage_platform_user',
        'target_type' => 'user',
        'target_id' => '99',
        'target_label' => 'jonas@onfleet.de',
        'result' => 'ok',
        'summary' => 'Blocked jonas@onfleet.de',
    ]);

    $this->actingAs($this->sysadmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('audit.0.action', 'blocked')
            ->where('audit.0.target', 'jonas@onfleet.de')
            ->where('audit.0.icon', 'user')
            // The detail line would only repeat the target, so it is dropped.
            ->where('audit.0.context', null)
            ->where('audit.0.actor.name', $this->sysadmin->name)
        );
});

it('shows no delta rather than an invented one when there is no baseline', function () {
    seedConversation($this->org->id, resolved: true, responseMs: 1000);

    $this->actingAs($this->sysadmin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.ticketsResolved.delta', null));
});
