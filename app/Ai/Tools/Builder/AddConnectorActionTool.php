<?php

namespace App\Ai\Tools\Builder;

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Enums\Visibility;
use App\Models\Integration;
use App\Models\Tool as ToolModel;
use App\Models\User;
use App\Services\Connectors\ConnectorActionResolver;
use App\Services\Integrations\IntegrationCaller;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Create a typed connector action on a REST connection, from the builder.
 *
 * The builder could create the CONNECTION and never the actions on it, so
 * `list_connector_actions` came back empty on an API the model had just
 * connected and a `connector.call` had nothing real to reference. The only way
 * to fill that list was the integrations admin — save a request, then "expose as
 * tool" — which is not a place a conversation can go. "Connect Slack and post
 * the summary there" was therefore two products away from working.
 *
 * Two rails, each deliberate:
 *
 *  - A READ is verified by firing it. The endpoint answering is what makes the
 *    action real, exactly as the sample call is what makes a connected object's
 *    mapping real. A write is created unverified and says so — calling it to
 *    find out would be the write.
 *  - `safe` is NOT settable here. It is the flag that lets a write skip the
 *    approval gate, so a model able to set it could switch off
 *    propose-don't-mutate on an action it invented in the same breath. Marking
 *    an action safe stays a human decision in the admin surface.
 */
class AddConnectorActionTool implements Tool
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private IntegrationCaller $caller,
        private ConnectorActionResolver $resolver,
        private User $user,
    ) {}

    public function name(): string
    {
        return 'add_connector_action';
    }

    public function description(): string
    {
        return <<<'DESC'
Create a typed connector action on a REST integration — one operation a workflow's
`connector.call` step can invoke (post a message, create a ticket, look up a
customer). Pass `integration_id`, a human `name`, the `method` and the `path`;
optionally `description`, `request_body_template` (a JSON string, {{placeholders}}
become the action's typed inputs) and `response_mapping` ({output_name: dot.path}).
Call this when list_connector_actions comes back empty for a connection a flow
needs to ACT on — without it there is no action id to reference and the step
cannot be composed. A read (GET) is CALLED once to verify it works and refuses if
it does not; a write is created unverified. New actions are never `safe`, so a
write halts for human approval the first time a workflow reaches it — that is the
propose-don't-mutate gate and it is not yours to open. Returns
{ok, action_id, effect, inputs, blast_radius, verified}. Then reference
`action_id` as the `tool_id` of a connector.call step.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('The REST integration this action runs against (from list_available_integrations).')
                ->required(),
            'name' => $schema->string()
                ->description('What the operation does, in a few words, e.g. "Post a Slack message".')
                ->required(),
            'method' => $schema->string()
                ->description('GET, POST, PUT, PATCH or DELETE. Decides the read/write effect, and therefore whether calls are gated.')
                ->required(),
            'path' => $schema->string()
                ->description('Path appended to the integration base URL. May carry {{placeholders}}, which become typed inputs (e.g. "/v3/orders/{{order_id}}").')
                ->required(),
            'description' => $schema->string()
                ->description('Longer description, for whoever picks this action later.'),
            'request_body_template' => $schema->string()
                ->description('JSON body as a STRING, with {{placeholders}} for the inputs (e.g. "{\"text\": \"{{message}}\"}").'),
            'response_mapping' => $schema->object()
                ->description('Names the typed outputs: {output_name: "dot.path.in.response"}. They land under {{steps.<id>.output.data.…}}.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        $integration = Integration::query()
            ->forAccountContext($this->user)
            ->find((string) ($args['integration_id'] ?? ''));
        if (! $integration instanceof Integration) {
            return $this->fail('Integration not found for this tenant.');
        }
        if ($integration->is_mcp) {
            return $this->fail("«{$integration->name}» is an MCP server: it publishes its own operations over the protocol, so there is nothing to author here. Its tools are already callable.");
        }

        if (! $this->user->can('create', ToolModel::class)) {
            return $this->fail('You do not have permission to create connector actions.');
        }

        $name = trim((string) ($args['name'] ?? ''));
        $path = trim((string) ($args['path'] ?? ''));
        $method = strtoupper(trim((string) ($args['method'] ?? '')));

        if ($name === '' || $path === '') {
            return $this->fail('A name and a path are required.');
        }
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $this->fail("Unknown method '{$method}'. Use GET, POST, PUT, PATCH or DELETE.");
        }

        // Verify a read by making it. A path with placeholders cannot be fired
        // as written (the caller has no values for them yet), so it is created
        // unverified rather than called with a literal "{{id}}" in the URL.
        $isRead = in_array($method, self::READ_METHODS, true);
        $templated = str_contains($path, '{{');
        $verified = false;

        if ($isRead && ! $templated) {
            try {
                $response = $this->caller->send($integration, $method, $path, actor: $this->user);
            } catch (\Throwable $e) {
                return $this->fail("The endpoint could not be reached: {$e->getMessage()}");
            }
            if (! $response->successful()) {
                return $this->fail("The endpoint {$method} {$path} returned HTTP {$response->status()} — fix the path (or the connection's authorization) before creating an action nobody can call.");
            }
            $verified = true;
        }

        $config = array_filter([
            'integration_id' => $integration->id,
            'method' => $method,
            'path' => $path,
            'request_body_template' => is_string($args['request_body_template'] ?? null) && $args['request_body_template'] !== ''
                ? $args['request_body_template']
                : null,
            'response_mapping' => is_array($args['response_mapping'] ?? null) && $args['response_mapping'] !== []
                ? $args['response_mapping']
                : null,
        ], fn ($v) => $v !== null);

        $action = ToolModel::create([
            'user_id' => $this->user->id,
            'organization_id' => $this->user->organization_id,
            'visibility' => $this->user->organization_id ? Visibility::Organization : Visibility::Private,
            'type' => ToolType::RestApi,
            'name' => $name,
            'description' => is_string($args['description'] ?? null) ? $args['description'] : null,
            'config' => $config,
            // Active, not draft. The status gates nothing in code (Tool's own
            // `active` scope has no callers) and this action exists because a
            // flow being built right now needs it — leaving it draft would only
            // make the list look unfinished. What actually gates a write is the
            // approval gate below, which no status would have added.
            'status' => AgentStatus::Active,
            // Never `safe`: see the class docblock. A write halts for approval.
            'safe' => false,
        ]);

        $contract = $this->resolver->resolve($action);

        return json_encode([
            'ok' => true,
            'action_id' => $action->id,
            'name' => $action->name,
            'effect' => $contract->effect->value,
            'inputs' => $contract->inputs,
            'outputs' => $contract->outputs,
            'blast_radius' => $contract->blastRadius,
            'verified' => $verified,
            'message' => $this->messageFor($contract->effect->value, $verified, $isRead, $templated),
        ], JSON_THROW_ON_ERROR);
    }

    private function messageFor(string $effect, bool $verified, bool $isRead, bool $templated): string
    {
        $head = 'Connector action created. Reference it as the `tool_id` of a connector.call step.';

        if ($verified) {
            return $head.' The endpoint was called once and answered, so this action is known to work.';
        }

        if ($isRead && $templated) {
            return $head.' Not verified: the path carries placeholders, so there was nothing concrete to call.';
        }

        return $head." Not verified — calling a {$effect} to test it would BE the write. The first workflow run that reaches it will halt for human approval, which is expected: tell the user they need to approve it once.";
    }

    private function fail(string $message): string
    {
        return json_encode([
            'ok' => false,
            'errors' => [['path' => '/', 'message' => $message, 'code' => 'bad_input']],
        ], JSON_THROW_ON_ERROR);
    }
}
