<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Enums\IntegrationKind;
use App\Enums\ToolType;
use App\Enums\Visibility;
use App\Http\Requests\Tool\StoreToolRequest;
use App\Http\Requests\Tool\UpdateToolRequest;
use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\Tool;
use App\Services\Integrations\IntegrationService;
use App\Services\ToolConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ToolController extends Controller
{
    public function __construct(
        private readonly ToolConfigService $toolConfigService,
        private readonly IntegrationService $integrationService,
    ) {}

    public function index(Request $request): Response
    {
        $typeFilter = $request->query('type');

        $query = Tool::query()
            ->forAccountContext($request->user())
            ->latest();

        if ($typeFilter && in_array($typeFilter, array_column(ToolType::cases(), 'value'))) {
            $query->where('type', $typeFilter);
        }

        $tools = $query->paginate(12)->withQueryString();

        $toolsByType = Tool::query()
            ->forAccountContext($request->user())
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        return Inertia::render('tools/Index', [
            'tools' => $tools,
            'toolsByType' => $toolsByType,
            'currentType' => $typeFilter,
            'toolTypes' => $this->selectableToolTypes(),
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $request->query('type');

        return Inertia::render('tools/Create', [
            'selectedType' => $type,
            'toolTypes' => $this->selectableToolTypes(),
            'availableTools' => Tool::forAccountContext($request->user())
                ->whereIn('type', ['function', 'mcp'])
                ->where('status', 'active')
                ->get(['id', 'name', 'type']),
            'mcpConnections' => $this->mcpConnectionOptions($request),
            'httpConnections' => $this->httpConnectionOptions($request),
            'dbConnections' => $this->dbConnectionOptions($request),
        ]);
    }

    /**
     * Tool types offered in the UI. `group` is intentionally excluded — it's
     * no longer surfaced as a creatable/filterable type.
     *
     * @return array<int, array{value: string, label: string, description: string}>
     */
    private function selectableToolTypes(): array
    {
        return collect(ToolType::cases())
            ->reject(fn (ToolType $type): bool => $type === ToolType::Group)
            ->map(fn (ToolType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),
            ])
            ->values()
            ->all();
    }

    /**
     * HTTP connections an http or graphql tool can borrow its base URL + auth
     * from. A connected tool is the *action*; the integration is the
     * *connection*.
     *
     * @return array<int, array{id: string, name: string, base_url: string, auth_type: string}>
     */
    private function httpConnectionOptions(Request $request): array
    {
        return $this->integrationService->listForUser($request->user())
            ->filter(fn (Integration $integration): bool => $integration->kind === IntegrationKind::Http)
            ->map(fn (Integration $integration): array => [
                'id' => $integration->id,
                'name' => $integration->name,
                'base_url' => $integration->base_url,
                'auth_type' => $integration->auth_type->value,
            ])
            ->values()
            ->all();
    }

    /**
     * Database connections a database tool can borrow its DSN from.
     *
     * @return array<int, array{id: string, name: string, base_url: string}>
     */
    private function dbConnectionOptions(Request $request): array
    {
        return $this->integrationService->listForUser($request->user())
            ->filter(fn (Integration $integration): bool => $integration->kind === IntegrationKind::Database)
            ->map(fn (Integration $integration): array => [
                'id' => $integration->id,
                'name' => $integration->name,
                'base_url' => $integration->base_url,
            ])
            ->values()
            ->all();
    }

    public function store(StoreToolRequest $request): RedirectResponse
    {
        $user = $request->user();
        $type = ToolType::from($request->type);
        $config = $request->config ?? [];

        // Encrypt sensitive fields
        if ($this->toolConfigService->hasSensitiveFields($type)) {
            $config = $this->toolConfigService->encryptConfig($type, $config);
        }

        $tool = Tool::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'type' => $request->type,
            'name' => $request->name,
            'description' => $request->description,
            'config' => $config,
            'status' => AgentStatus::Draft,
        ]);

        if ($request->type === 'group' && $request->has('tool_ids')) {
            foreach ($request->tool_ids as $index => $toolId) {
                $tool->groupItems()->create([
                    'tool_id' => $toolId,
                    'order' => $index,
                ]);
            }
        }

        return to_route('tools.show', $tool);
    }

    public function show(Request $request, Tool $tool): Response
    {
        $this->authorize('view', $tool);

        $tool->load(['groupItems.tool']);

        // Mask sensitive fields for display
        $toolData = $tool->toArray();
        if ($this->toolConfigService->hasSensitiveFields($tool->type)) {
            $toolData['config'] = $this->toolConfigService->maskSensitiveFields(
                $tool->type,
                $tool->config ?? []
            );
        }

        return Inertia::render('tools/Show', [
            'tool' => $toolData,
            'mcpAuthorization' => $this->mcpAuthorizationStatus($request, $tool),
            'linkedIntegration' => $this->linkedIntegration($tool),
        ]);
    }

    /**
     * The Connection (Integration) this tool runs against, when it references
     * one via `config.integration_id`. A tool is the *action*; the integration
     * is the *connection* (base URL + auth) it borrows. Returns null for
     * connectionless tools (function, group) or tools without a linked
     * integration.
     *
     * @return array{id: string, name: string, is_mcp: bool}|null
     */
    private function linkedIntegration(Tool $tool): ?array
    {
        $integrationId = $tool->config['integration_id'] ?? null;
        if (empty($integrationId)) {
            return null;
        }

        $integration = Integration::find($integrationId);
        if (! $integration instanceof Integration) {
            return null;
        }

        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'is_mcp' => (bool) $integration->is_mcp,
        ];
    }

    /**
     * Per-user OAuth authorization status for an MCP tool, or null when the
     * tool isn't an OAuth-backed MCP tool.
     *
     * @return array{connected: bool, authorize_url: string, integration_name: string}|null
     */
    private function mcpAuthorizationStatus(Request $request, Tool $tool): ?array
    {
        $config = $tool->config ?? [];
        if ($tool->type->value !== 'mcp' || ($config['auth_type'] ?? null) !== 'oauth2' || empty($config['integration_id'])) {
            return null;
        }

        $integration = Integration::find($config['integration_id']);

        $token = IntegrationUserToken::query()
            ->where('user_id', $request->user()->id)
            ->where('integration_id', $config['integration_id'])
            ->first();

        return [
            'connected' => $token instanceof IntegrationUserToken && $token->isAuthorized(),
            'authorize_url' => route('tools.oauth2.authorize', $tool),
            'integration_name' => $integration?->name ?? '',
        ];
    }

    public function edit(Request $request, Tool $tool): Response
    {
        $this->authorize('update', $tool);

        $tool->load(['groupItems.tool']);

        // Mask sensitive fields for editing
        $toolData = $tool->toArray();
        if ($this->toolConfigService->hasSensitiveFields($tool->type)) {
            $toolData['config'] = $this->toolConfigService->maskSensitiveFields(
                $tool->type,
                $tool->config ?? []
            );
        }

        return Inertia::render('tools/Edit', [
            'tool' => $toolData,
            'toolTypes' => $this->selectableToolTypes(),
            'availableTools' => Tool::forAccountContext($request->user())
                ->whereIn('type', ['function', 'mcp'])
                ->where('status', 'active')
                ->where('id', '!=', $tool->id)
                ->get(['id', 'name', 'type']),
            'oauth2Integrations' => $this->oauth2IntegrationOptions($request),
            'oauth2AuthorizeUrl' => route('tools.oauth2.authorize', $tool),
            'httpConnections' => $this->httpConnectionOptions($request),
            'dbConnections' => $this->dbConnectionOptions($request),
        ]);
    }

    public function update(UpdateToolRequest $request, Tool $tool): RedirectResponse
    {
        $config = $request->config ?? $tool->config;

        // Handle sensitive fields encryption
        if ($this->toolConfigService->hasSensitiveFields($tool->type)) {
            $config = $this->mergeAndEncryptConfig($tool, $config);
        }

        $tool->update([
            'name' => $request->name,
            'description' => $request->description,
            'status' => $request->status ?? $tool->status,
            'config' => $config,
        ]);

        if ($tool->type->value === 'group' && $request->has('tool_ids')) {
            $tool->groupItems()->delete();
            foreach ($request->tool_ids as $index => $toolId) {
                $tool->groupItems()->create([
                    'tool_id' => $toolId,
                    'order' => $index,
                ]);
            }
        }

        return to_route('tools.show', $tool);
    }

    public function destroy(Request $request, Tool $tool): RedirectResponse
    {
        $this->authorize('delete', $tool);

        $tool->delete();

        return to_route('tools.index');
    }

    /**
     * MCP connections (integrations flagged as MCP) the user can turn into a
     * tool, with whether the current user is already connected (authorized).
     * Non-OAuth MCP servers need no per-user authorization, so they always
     * count as connected.
     *
     * @return array<int, array{id: string, name: string, base_url: string, requires_auth: bool, connected: bool}>
     */
    private function mcpConnectionOptions(Request $request): array
    {
        $user = $request->user();

        $integrations = $this->integrationService->listForUser($user)
            ->where('is_mcp', true);

        $authorizedIds = IntegrationUserToken::query()
            ->where('user_id', $user->id)
            ->whereIn('integration_id', $integrations->pluck('id'))
            ->get()
            ->filter(fn (IntegrationUserToken $token): bool => $token->isAuthorized())
            ->pluck('integration_id')
            ->all();

        return $integrations->map(function (Integration $integration) use ($authorizedIds): array {
            $requiresAuth = $integration->auth_type->isOAuth2();

            return [
                'id' => $integration->id,
                'name' => $integration->name,
                'base_url' => $integration->base_url,
                'requires_auth' => $requiresAuth,
                'connected' => $requiresAuth
                    ? in_array($integration->id, $authorizedIds, true)
                    : true,
            ];
        })->values()->all();
    }

    /**
     * OAuth 2.0 integrations the user can link to an MCP tool. The integration
     * carries the shared client config; whether the *current user* is
     * authorized comes from their own per-user token store. The actual
     * authorization happens per-user from the tool (see tools.oauth2.authorize).
     *
     * @return array<int, array{id: string, name: string, auth_type: string, is_authorization_code: bool, authorized: bool}>
     */
    private function oauth2IntegrationOptions(Request $request): array
    {
        $user = $request->user();
        $integrations = $this->integrationService->listOAuth2ForUser($user);

        $authorizedIds = IntegrationUserToken::query()
            ->where('user_id', $user->id)
            ->whereIn('integration_id', $integrations->pluck('id'))
            ->get()
            ->filter(fn (IntegrationUserToken $token): bool => $token->isAuthorized())
            ->pluck('integration_id')
            ->all();

        return $integrations->map(fn (Integration $integration): array => [
            'id' => $integration->id,
            'name' => $integration->name,
            'auth_type' => $integration->auth_type->value,
            'is_authorization_code' => $integration->auth_type->value === 'oauth2_auth_code',
            'authorized' => in_array($integration->id, $authorizedIds, true),
        ])->all();
    }

    /**
     * Merge new config with existing encrypted values and encrypt new sensitive fields.
     *
     * If a sensitive field is empty/null in the new config, keep the existing encrypted value.
     * If a new value is provided, encrypt it.
     */
    private function mergeAndEncryptConfig(Tool $tool, array $newConfig): array
    {
        $existingConfig = $tool->config ?? [];
        $encryptedFields = match ($tool->type->value) {
            'rest_api', 'graphql', 'mcp' => ['auth_config'],
            'database' => ['username', 'password'],
            default => [],
        };

        foreach ($encryptedFields as $field) {
            // If new value is empty but we have an existing value, keep the existing
            if (empty($newConfig[$field]) && ! empty($existingConfig[$field])) {
                $newConfig[$field] = $existingConfig[$field];
            }
        }

        return $this->toolConfigService->encryptConfig($tool->type, $newConfig);
    }
}
