<?php

namespace App\Services\Chat\Actions;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Express\ExpressLauncher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Executes a "build this landing with the app builder" proposal: provisions a
 * fresh app + builder conversation and runs ONE real builder turn (the full
 * tool set — rule 1d-land, bespoke authoring, the design gate) asynchronously,
 * exactly like the chat's app-build handoff. The card is the ASK-FIRST answer:
 * the chat model offers "app builder vs. direct authoring", and clicking
 * Execute on this card IS choosing the builder.
 *
 * A linked "⏳ construyendo tu landing…" chat message is created so the async
 * job flips it to "✅ lista" (or an honest failure) when the turn lands —
 * the user keeps chatting meanwhile.
 */
class BuildLandingAction implements ActionHandler
{
    public function key(): string
    {
        return 'build_landing';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{summary: string, data?: array<string, mixed>}
     */
    public function execute(Chat $chat, array $payload): array
    {
        $owner = $chat->user ?? User::find($chat->user_id);
        if ($owner === null) {
            throw new RuntimeException('The chat owner could not be resolved.');
        }

        $params = (array) ($payload['parameters'] ?? []);
        $brief = trim((string) ($params['brief'] ?? ($params['description'] ?? '')));
        if ($brief === '') {
            throw new RuntimeException('The proposal needs a `brief` describing the landing.');
        }

        $ctx = app(TenantContext::class);
        $previousUser = Auth::user();
        $previousOrg = $ctx->organizationId();
        $previousUid = $ctx->userId();

        Auth::setUser($owner);
        $ctx->set($owner->organization_id, $owner->id);

        try {
            $launcher = app(ExpressLauncher::class);
            [$app, $conversation] = $launcher->provisionApp($owner, $brief);
            if (! empty($params['name']) && is_string($params['name'])) {
                $app->forceFill(['name' => mb_substr(trim($params['name']), 0, 100)])->save();
            }

            // The linked progress message the async job flips on completion.
            $assistant = ChatMessage::create([
                'chat_id' => $chat->id,
                'role' => 'assistant',
                'content' => "⏳ Estoy construyendo tu landing **{$app->name}** con el builder. "
                    .'Sigue la conversación — te avisaré aquí mismo cuando esté lista '
                    .'(suele tardar entre 1 y 3 minutos).',
                'status' => 'complete',
                'message_type' => 'text',
            ]);

            $launcher->launchAppBuild($app, $conversation, $brief, null, $assistant);

            return [
                'summary' => "El builder está construyendo la landing «{$app->name}» — el chat avisará cuando esté lista.",
                'data' => ['app_slug' => $app->slug, 'conversation_id' => $conversation->id],
            ];
        } finally {
            if ($previousUser !== null) {
                Auth::setUser($previousUser);
            } else {
                Auth::forgetUser();
            }
            $ctx->set($previousOrg, $previousUid);
        }
    }
}
