<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationAuthType;
use App\Enums\Visibility;
use App\Http\Requests\Integrations\StoreIntegrationRequest;
use App\Http\Requests\Integrations\UpdateIntegrationRequest;
use App\Models\Integration;
use App\Services\Integrations\IntegrationService;
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

    public function create(): Response
    {
        $this->authorize('create', Integration::class);

        return Inertia::render('system/integrations/Form', [
            'mode' => 'create',
            'authTypes' => $this->authTypeOptions(),
            'visibilities' => $this->visibilityOptions(),
        ]);
    }

    public function store(StoreIntegrationRequest $request): RedirectResponse
    {
        $this->authorize('create', Integration::class);

        $integration = $this->service->create($request->user(), $request->validated());

        return to_route('system.integrations.show', ['integration' => $integration->id]);
    }

    public function show(Integration $integration): Response
    {
        $this->authorize('view', $integration);

        $integration->load(['environments.variables', 'requests']);

        return Inertia::render('system/integrations/Show', [
            'integration' => $this->present($integration),
        ]);
    }

    public function edit(Integration $integration): Response
    {
        $this->authorize('update', $integration);

        return Inertia::render('system/integrations/Form', [
            'mode' => 'edit',
            'integration' => $this->present($integration),
            'authTypes' => $this->authTypeOptions(),
            'visibilities' => $this->visibilityOptions(),
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
     * @return array<string, mixed>
     */
    private function summarize(Integration $integration): array
    {
        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'slug' => $integration->slug,
            'base_url' => $integration->base_url,
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
}
