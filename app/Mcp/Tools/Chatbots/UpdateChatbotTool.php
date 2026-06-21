<?php

namespace App\Mcp\Tools\Chatbots;

use App\Enums\ChatbotStatus;
use App\Enums\Visibility;
use App\Models\User;
use App\Support\Chatbots\ChatbotConfigRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update a chatbot\'s own configuration (partial — only supplied fields change): name, description, status (draft/active/inactive — this is how you publish it), visibility, widget config (appearance/behavior/advanced), or allowed_origins. To change the conversation logic use update_bot_flow instead.')]
class UpdateChatbotTool extends ChatbotTool
{
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $chatbotId = $request->validate(['chatbot_id' => ['required', 'string']])['chatbot_id'];

        try {
            $chatbot = $this->resolveChatbot($chatbotId, $user);
        } catch (ModelNotFoundException) {
            return Response::error("No chatbot '{$chatbotId}' is visible to you.");
        }

        if (! $user->can('update', $chatbot)) {
            return Response::error('You do not have permission to update this chatbot.');
        }

        $validated = $request->validate([
            'chatbot_id' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', Rule::enum(ChatbotStatus::class)],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            ...ChatbotConfigRules::widgetConfig(),
        ]);

        $attributes = [];
        foreach (['name', 'description', 'status', 'config', 'allowed_origins'] as $field) {
            if (array_key_exists($field, $validated)) {
                $attributes[$field] = $validated[$field];
            }
        }

        if ($attributes !== []) {
            $chatbot->update($attributes);
        }

        // Visibility goes through its own method so the companion channel and any
        // sharing side effects stay in sync.
        if (array_key_exists('visibility', $validated)) {
            $chatbot->updateVisibility(Visibility::from($validated['visibility']), $user);
        }

        return Response::json($this->chatbotPayload($chatbot->refresh()));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'chatbot_id' => $schema->string()->description('The id of the chatbot to update.')->required(),
            'name' => $schema->string()->description('New name.'),
            'description' => $schema->string()->description('New description.'),
            'status' => $schema->string()->enum(array_column(ChatbotStatus::cases(), 'value'))->description('draft, active, or inactive (active publishes the widget).'),
            'visibility' => $schema->string()->enum(array_column(Visibility::cases(), 'value'))->description('private, organization, global, or public.'),
            'config' => $schema->object()->description('Replace widget config: { appearance, behavior, advanced }.'),
            'allowed_origins' => $schema->array()->description('Replace the embed origin allowlist (max 20 URLs).'),
        ];
    }
}
