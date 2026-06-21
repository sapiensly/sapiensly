<?php

namespace App\Mcp\Tools\Chatbots;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\ChatbotStatus;
use App\Enums\Visibility;
use App\Models\BotFlow;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\User;
use App\Support\Chatbots\ChatbotConfigRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a widget chatbot. It starts as a draft with a blank bot flow (a single start node) and a companion widget channel; author its conversation with scaffold_bot_flow + update_bot_flow, then update_chatbot status="active" to publish.')]
class CreateChatbotTool extends ChatbotTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->can('create', Chatbot::class)) {
            return Response::error('You do not have permission to create chatbots.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            ...ChatbotConfigRules::widgetConfig(),
        ]);

        $visibility = $user->organization_id ? Visibility::Organization : Visibility::Private;

        $chatbot = DB::transaction(function () use ($user, $validated, $visibility): Chatbot {
            // Companion channel first — channels centralise tenant scope/status
            // across channel types; a widget bot's agents live in its bot flow.
            $channel = Channel::create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'visibility' => $visibility,
                'channel_type' => ChannelType::Widget,
                'name' => $validated['name'],
                'status' => ChannelStatus::Draft,
            ]);

            $chatbot = Chatbot::create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'visibility' => $visibility,
                'channel_id' => $channel->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'config' => $validated['config'] ?? Chatbot::getDefaultConfig(),
                'allowed_origins' => $validated['allowed_origins'] ?? null,
                'status' => ChatbotStatus::Draft,
            ]);

            ChatbotApiToken::create([
                'chatbot_id' => $chatbot->id,
                'name' => 'Default Token',
                'token' => ChatbotApiToken::generateToken(),
                'abilities' => ['chat', 'feedback'],
            ]);

            BotFlow::blankForChatbot($chatbot);

            return $chatbot;
        });

        return Response::json($this->chatbotPayload($chatbot));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The chatbot name.')->required(),
            'description' => $schema->string()->description('What the chatbot does.'),
            'config' => $schema->object()->description('Optional widget config: { appearance: { primary_color, position, welcome_message, widget_title, ... }, behavior: { auto_open_delay, collect_email, ... }, advanced: { custom_css, custom_font_family } }.'),
            'allowed_origins' => $schema->array()->description('Optional list of origin URLs allowed to embed the widget (max 20).'),
        ];
    }
}
