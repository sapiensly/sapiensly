<?php

use App\Enums\ChatbotStatus;
use App\Models\Agent;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * BindWidgetTenantContext must scope the tenant connection from the chatbot's
 * owner, so widget rows are RLS-keyed even though the widget runs without a
 * session user. We assert the key the BEFORE INSERT trigger stamps from the
 * GUCs the middleware set — proof the scope flowed end to end.
 */
function widgetTokenFor(Chatbot $chatbot): string
{
    return ChatbotApiToken::create([
        'chatbot_id' => $chatbot->id,
        'name' => 'Test Token',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat'],
    ])->token;
}

function createSessionRow(Chatbot $chatbot): object
{
    $token = widgetTokenFor($chatbot);

    test()->postJson('/api/widget/v1/sessions', [], [
        'Authorization' => "Bearer {$token}",
    ])->assertCreated();

    return DB::connection('tenant')->table('widget_sessions')
        ->where('chatbot_id', $chatbot->id)
        ->first();
}

it('stamps the chatbot owner organization onto widget rows (business mode)', function () {
    $user = User::factory()->create();
    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $agent = Agent::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $chatbot = Chatbot::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => ChatbotStatus::Active,
    ]);

    $row = createSessionRow($chatbot);

    expect($row->organization_id)->toBe($org->id);
});

it('stamps the chatbot owner user onto widget rows (personal mode)', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create(['user_id' => $user->id, 'status' => 'active']);
    $chatbot = Chatbot::factory()->create([
        'user_id' => $user->id,
        'organization_id' => null,
        'status' => ChatbotStatus::Active,
    ]);

    $row = createSessionRow($chatbot);

    expect((int) $row->user_id)->toBe($user->id)
        ->and($row->organization_id)->toBeNull();
});
