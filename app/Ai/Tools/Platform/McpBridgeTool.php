<?php

namespace App\Ai\Tools\Platform;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool as ToolContract;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Mcp\Request as McpRequest;
use Stringable;

/**
 * Adapts one MCP tool ({@see SapiensTool}) into a Laravel AI SDK tool, so the
 * full MCP catalogue is callable by internal agents during inference.
 *
 * The MCP handler runs AS THE AGENT'S OWNER: we set both the auth user and the
 * tenant context (RLS GUCs) to the owner for the duration of the call and
 * restore them after. That is what makes the handler's own forAccountContext +
 * $user->can() + Postgres RLS cap the call at the owner — an agent can never do
 * more than the user who owns it. Runtime/debate jobs don't install tenant
 * middleware, so setting it here is required, not just defensive.
 *
 * Each instance is given a unique class basename by RuntimeToolFactory (the SDK
 * names tools by class_basename, not a name() method); see PlatformToolsFactory.
 */
class McpBridgeTool implements ToolContract
{
    /** Cap on nested invoke_agent calls — a runaway recursion backstop on top of AiSpendGuard. */
    private const MAX_INVOKE_DEPTH = 3;

    private static int $invokeDepth = 0;

    private ?SapiensTool $instance = null;

    public function __construct(private string $mcpToolClass, private User $owner) {}

    public function description(): Stringable|string
    {
        return $this->mcp()->description();
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->mcp()->schema($schema);
    }

    public function handle(AiRequest $request): Stringable|string
    {
        $tool = $this->mcp();
        $isInvoke = $tool->name() === 'invoke_agent';

        if ($isInvoke && self::$invokeDepth >= self::MAX_INVOKE_DEPTH) {
            return 'Error: agent invocation depth limit ('.self::MAX_INVOKE_DEPTH.') reached — refusing to nest agent calls further.';
        }

        $ctx = app(TenantContext::class);
        $previousUser = Auth::user();
        $previousOrg = $ctx->organizationId();
        $previousUid = $ctx->userId();

        Auth::setUser($this->owner);
        $ctx->set($this->owner->organization_id, $this->owner->id);
        if ($isInvoke) {
            self::$invokeDepth++;
        }

        try {
            $response = $tool->handle(new McpRequest($request->toArray()));

            $content = $response->content();
            $text = method_exists($content, '__toString')
                ? (string) $content
                : (string) json_encode($content->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $response->isError() ? 'Error: '.$text : $text;
        } catch (ValidationException $e) {
            // A bad model-supplied argument must not throw out of the agent loop.
            return 'Error: '.implode(' ', $e->validator->errors()->all());
        } finally {
            if ($isInvoke) {
                self::$invokeDepth--;
            }
            if ($previousUser !== null) {
                Auth::setUser($previousUser);
            } else {
                Auth::forgetUser();
            }
            $ctx->set($previousOrg, $previousUid);
        }
    }

    private function mcp(): SapiensTool
    {
        return $this->instance ??= app($this->mcpToolClass);
    }
}
