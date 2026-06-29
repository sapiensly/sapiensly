<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationAuthType;
use App\Enums\Visibility;
use App\Http\Requests\Integrations\StoreIntegrationRequest;
use App\Http\Requests\Integrations\UpdateIntegrationRequest;
use App\Models\Integration;
use App\Models\Tool;
use App\Services\Integrations\IntegrationService;
use App\Services\Integrations\OAuth2\OAuth2DiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationController extends Controller
{
    public function __construct(
        private IntegrationService $service,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Integration::class);

        $integrations = $this->service->listForUser(
            $request->user(),
            $request->string('search')->toString() ?: null,
            $request->string('auth_type')->toString() ?: null,
        );

        return Inertia::render('system/integrations/Index', [
            'integrations' => $integrations->map(fn (Integration $i) => $this->summarize($i)),
            'filters' => [
                'search' => $request->input('search'),
                'auth_type' => $request->input('auth_type'),
            ],
            'authTypes' => $this->authTypeOptions(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Integration::class);

        return Inertia::render('system/integrations/Form', [
            'mode' => 'create',
            'authTypes' => $this->authTypeOptions(),
            'visibilities' => $this->visibilityOptions(),
            'template' => $this->resolveIntegrationTemplate($request->query('template')),
            'kind' => $request->query('kind'),
            'oauthCallbackUrl' => route('integrations.oauth2.callback'),
        ]);
    }

    public function templates(): Response
    {
        $this->authorize('create', Integration::class);

        return Inertia::render('system/integrations/Templates');
    }

    public function store(StoreIntegrationRequest $request): RedirectResponse
    {
        $this->authorize('create', Integration::class);

        $integration = $this->service->create($request->user(), $request->validated());

        return to_route('system.integrations.show', ['integration' => $integration->id]);
    }

    public function show(Request $request, Integration $integration): Response
    {
        $this->authorize('view', $integration);

        $integration->load(['environments.variables', 'requests']);

        return Inertia::render('system/integrations/Show', [
            'integration' => $this->present($integration),
            'linkedTools' => $this->linkedTools($request, $integration),
        ]);
    }

    /**
     * The Tools (agent actions) that run against this connection, i.e. tools
     * whose `config.integration_id` points at this integration. An integration
     * is the *connection*; each linked tool is an *action* an agent can take
     * through it. Scoped to what the viewer can see.
     *
     * @return array<int, array{id: string, name: string, type: string, effect: string|null, status: string}>
     */
    private function linkedTools(Request $request, Integration $integration): array
    {
        return Tool::query()
            ->forAccountContext($request->user())
            ->where('config->integration_id', $integration->id)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'effect', 'status'])
            ->map(fn (Tool $tool): array => [
                'id' => $tool->id,
                'name' => $tool->name,
                'type' => $tool->type->value,
                'effect' => $tool->effect?->value,
                'status' => $tool->status->value,
            ])
            ->all();
    }

    public function edit(Integration $integration): Response
    {
        $this->authorize('update', $integration);

        return Inertia::render('system/integrations/Form', [
            'mode' => 'edit',
            'integration' => $this->present($integration),
            'authTypes' => $this->authTypeOptions(),
            'visibilities' => $this->visibilityOptions(),
            'oauthCallbackUrl' => route('integrations.oauth2.callback'),
        ]);
    }

    public function update(UpdateIntegrationRequest $request, Integration $integration): RedirectResponse
    {
        $this->authorize('update', $integration);

        $this->service->update($integration, $request->validated());

        return to_route('system.integrations.show', ['integration' => $integration->id]);
    }

    public function destroy(Integration $integration): RedirectResponse
    {
        $this->authorize('delete', $integration);

        $this->service->delete($integration);

        return to_route('system.integrations.index');
    }

    public function duplicate(Request $request, Integration $integration): RedirectResponse
    {
        $this->authorize('create', Integration::class);
        $this->authorize('view', $integration);

        $copy = $this->service->duplicate($integration, $request->user());

        return to_route('system.integrations.show', ['integration' => $copy->id]);
    }

    public function testConnection(Integration $integration): JsonResponse
    {
        $this->authorize('execute', $integration);

        return response()->json($this->service->testConnection($integration));
    }

    /**
     * Tests a connection against a payload that isn't yet persisted (form draft).
     */
    public function testConnectionForPayload(Request $request): JsonResponse
    {
        $this->authorize('create', Integration::class);

        $validated = $request->validate([
            'base_url' => ['required', 'string', 'max:500'],
            'auth_type' => ['required', 'string'],
            'auth_config' => ['nullable', 'array'],
            'allow_insecure_tls' => ['nullable', 'boolean'],
        ]);

        $draft = new Integration([
            'name' => 'draft',
            'slug' => 'draft-'.uniqid(),
            'base_url' => $validated['base_url'],
            'auth_type' => $validated['auth_type'],
            'auth_config' => $validated['auth_config'] ?? [],
            'allow_insecure_tls' => (bool) ($validated['allow_insecure_tls'] ?? false),
        ]);
        $draft->setRelation('environments', collect());

        return response()->json($this->service->testConnection($draft));
    }

    /**
     * Auto-configure an OAuth 2.0 Authorization Code integration from a single
     * URL: discover the authorization server, its endpoints, and register a
     * client dynamically when the server supports it (the MCP flow).
     */
    public function discoverOAuth2(Request $request, OAuth2DiscoveryService $discovery): JsonResponse
    {
        $this->authorize('create', Integration::class);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:500', 'regex:/^https?:\/\//i'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $result = $discovery->autoConfigure(
                url: $validated['url'],
                redirectUri: route('integrations.oauth2.callback'),
                clientName: $validated['name'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            ...$result,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Integration $integration): array
    {
        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'slug' => $integration->slug,
            'base_url' => $integration->base_url,
            'is_mcp' => (bool) $integration->is_mcp,
            'auth_type' => $integration->auth_type instanceof IntegrationAuthType
                ? $integration->auth_type->value
                : $integration->auth_type,
            'visibility' => $integration->visibility instanceof Visibility
                ? $integration->visibility->value
                : $integration->visibility,
            'status' => $integration->status,
            'color' => $integration->color,
            'icon' => $integration->icon,
            'last_tested_at' => $integration->last_tested_at?->toIso8601String(),
            'last_test_status' => $integration->last_test_status,
            'request_count' => $integration->requests()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Integration $integration): array
    {
        return $this->summarize($integration) + [
            'description' => $integration->description,
            'default_headers' => $integration->default_headers,
            'allow_insecure_tls' => $integration->allow_insecure_tls,
            'active_environment_id' => $integration->active_environment_id,
            'last_test_message' => $integration->last_test_message,
            'masked_auth_config' => $this->service->maskAuthConfig($integration),
            'environments' => $integration->environments->map(fn ($env) => [
                'id' => $env->id,
                'name' => $env->name,
                'sort_order' => $env->sort_order,
                'variables' => $env->variables->map(fn ($var) => [
                    'id' => $var->id,
                    'key' => $var->key,
                    'value' => $var->is_secret ? '••••' : (string) $var->value,
                    'is_secret' => $var->is_secret,
                    'description' => $var->description,
                ]),
            ]),
            'requests' => $integration->requests->map(fn ($req) => [
                'id' => $req->id,
                'name' => $req->name,
                'folder' => $req->folder,
                'method' => $req->method,
                'path' => $req->path,
                'sort_order' => $req->sort_order,
            ]),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function authTypeOptions(): array
    {
        return collect(IntegrationAuthType::cases())
            ->map(fn (IntegrationAuthType $c) => ['value' => $c->value, 'label' => $c->label()])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function visibilityOptions(): array
    {
        return collect(Visibility::cases())
            ->map(fn (Visibility $c) => ['value' => $c->value, 'label' => $c->label()])
            ->values()
            ->all();
    }

    /**
     * Holds the server-side source of truth for "Conexiones" presets.
     * Landing on `/system/integrations/create?template=<slug>` seeds the
     * Create form with the matching preset; unknown slugs return null and
     * fall back to the form's blank defaults.
     *
     * GitHub uses the OAuth 2.0 Authorization Code flow (browser redirect),
     * so the preset ships the provider endpoints + the callback URL this
     * app listens on. The user only has to paste Client ID/Secret, save,
     * and click "Authorize with GitHub" on the integration's Show page.
     *
     * GitHub OAuth Apps do NOT support PKCE (it's a GitHub App feature),
     * so we ship the preset with pkce=false on purpose.
     *
     * @return array{name: string, description: string, base_url: string, auth_type: string, default_headers: array<int, array{key: string, value: string}>, auth_config: array<string, mixed>}|null
     */
    private function resolveIntegrationTemplate(?string $slug): ?array
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        $templates = [
            'github' => [
                'name' => 'GitHub API',
                'description' => 'GitHub REST API',
                'base_url' => 'https://api.github.com',
                'auth_type' => IntegrationAuthType::OAuth2AuthorizationCode->value,
                'default_headers' => [
                    ['key' => 'Accept', 'value' => 'application/vnd.github+json'],
                    ['key' => 'X-GitHub-Api-Version', 'value' => '2022-11-28'],
                ],
                'auth_config' => [
                    'authorize_url' => 'https://github.com/login/oauth/authorize',
                    'token_url' => 'https://github.com/login/oauth/access_token',
                    'client_id' => '',
                    'client_secret' => '',
                    'redirect_uri' => route('integrations.oauth2.callback'),
                    'scope' => 'repo read:user',
                    'pkce' => false,
                ],
            ],
        ];

        return $templates[$slug] ?? null;
    }
}
