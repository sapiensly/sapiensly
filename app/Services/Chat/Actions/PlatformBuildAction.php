<?php

namespace App\Services\Chat\Actions;

use App\Ai\Tools\Platform\McpBridgeTool;
use App\Mcp\Tools\SapiensTool;
use App\Models\Chat;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request as McpRequest;
use RuntimeException;

/**
 * Executes a platform "build" proposal (create_app / create_chatbot /
 * create_integration / create_knowledge_base / create_agent) by running the
 * corresponding MCP tool AS THE CHAT OWNER, mirroring
 * {@see McpBridgeTool}: the owner's authorization + RLS
 * cap the call, so a build can never do more than the user who owns the chat.
 *
 * One instance is registered per build action_type in {@see ActionRegistry}; the
 * proposal's `parameters` are passed straight to the MCP tool, which validates
 * them. A tool error throws so the executor surfaces it (HTTP 422) and the card
 * stays actionable instead of recording a fake success.
 */
class PlatformBuildAction implements ActionHandler
{
    /**
     * @param  class-string<SapiensTool>  $mcpToolClass
     */
    public function __construct(
        private string $key,
        private string $mcpToolClass,
    ) {}

    public function key(): string
    {
        return $this->key;
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

        $ctx = app(TenantContext::class);
        $previousUser = Auth::user();
        $previousOrg = $ctx->organizationId();
        $previousUid = $ctx->userId();

        Auth::setUser($owner);
        $ctx->set($owner->organization_id, $owner->id);

        try {
            $tool = app($this->mcpToolClass);
            $response = $tool->handle(new McpRequest($params));

            $content = $response->content();
            $text = method_exists($content, '__toString')
                ? (string) $content
                : (string) json_encode($content->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($response->isError()) {
                throw new RuntimeException($text);
            }

            $label = (string) ($payload['action_label'] ?? 'the build');

            return [
                'summary' => 'Done — '.$label.'.',
                'data' => ['result' => $text],
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
