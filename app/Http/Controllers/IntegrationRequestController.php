<?php

namespace App\Http\Controllers;

use App\DTOs\IntegrationExecutionResult;
use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Enums\Visibility;
use App\Http\Requests\Integrations\ExecuteIntegrationRequestRequest;
use App\Http\Requests\Integrations\StoreIntegrationRequestRequest;
use App\Http\Requests\Integrations\UpdateIntegrationRequestRequest;
use App\Models\Integration;
use App\Models\IntegrationEnvironment;
use App\Models\IntegrationRequest;
use App\Models\Tool;
use App\Services\Integrations\IntegrationRequestExecutor;
use App\Services\Integrations\IntegrationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationRequestController extends Controller
{
    public function __construct(
        private IntegrationRequestService $service,
        private IntegrationRequestExecutor $executor,
    ) {}

    public function store(StoreIntegrationRequestRequest $request, Integration $integration): RedirectResponse
    {
        $this->authorize('update', $integration);

        $created = $this->service->create($integration, $request->user(), $request->validated());

        return to_route('system.integrations.requests.show', ['request' => $created->id]);
    }

    public function show(IntegrationRequest $request): Response
    {
        $this->authorize('view', $request->integration);

        return Inertia::render('system/integrations/RequestBuilder', [
            'request' => $this->present($request),
            'integration' => [
                'id' => $request->integration->id,
                'name' => $request->integration->name,
                'base_url' => $request->integration->base_url,
                'active_environment_id' => $request->integration->active_environment_id,
                'environments' => $request->integration->environments->map(fn ($env) => [
                    'id' => $env->id,
                    'name' => $env->name,
                    'variables' => $env->variables->map(fn ($v) => [
                        'key' => $v->key,
                        'is_secret' => $v->is_secret,
                    ]),
                ]),
            ],
        ]);
    }

    public function update(UpdateIntegrationRequestRequest $httpRequest, IntegrationRequest $request): RedirectResponse
    {
        $this->authorize('update', $request->integration);

        $this->service->update($request, $httpRequest->validated());

        return to_route('system.integrations.requests.show', ['request' => $request->id]);
    }

    public function destroy(IntegrationRequest $request): RedirectResponse
    {
        $this->authorize('update', $request->integration);

        $this->service->delete($request);

        return to_route('system.integrations.show', ['integration' => $request->integration_id]);
    }

    public function duplicate(Request $httpRequest, IntegrationRequest $request): RedirectResponse
    {
        $this->authorize('update', $request->integration);

        $copy = $this->service->duplicate($request, $httpRequest->user());

        return to_route('system.integrations.requests.show', ['request' => $copy->id]);
    }

    /**
     * Promote a saved Postman-style request into an agent-invocable Tool that
     * runs through this same connection. The request is the human-tested draft;
     * the tool is its agent-facing twin — one operation, defined once.
     */
    public function exposeAsTool(Request $httpRequest, IntegrationRequest $request): RedirectResponse
    {
        $this->authorize('update', $request->integration);

        $user = $httpRequest->user();
        $method = strtoupper((string) $request->method);

        $tool = Tool::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'type' => ToolType::RestApi,
            'name' => $request->name,
            'description' => $request->description,
            'config' => array_filter([
                'integration_id' => $request->integration_id,
                'method' => $method ?: 'GET',
                'path' => $request->path ?? '',
                'headers' => $this->enabledHeaderMap($request->headers ?? []),
                'request_body_template' => $this->bodyTemplate($request, $method),
            ], fn ($value) => $value !== null && $value !== []),
            'status' => AgentStatus::Draft,
        ]);

        return to_route('tools.show', $tool);
    }

    /**
     * Flatten a request's header rows ([{key,value,enabled?}]) into the
     * key=>value map a rest_api tool config expects, keeping enabled rows only.
     *
     * @param  array<int, array{key?: string, value?: string, enabled?: bool}>  $headers
     * @return array<string, string>
     */
    private function enabledHeaderMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $header) {
            $key = $header['key'] ?? '';
            if ($key === '' || ($header['enabled'] ?? true) === false) {
                continue;
            }
            $map[$key] = (string) ($header['value'] ?? '');
        }

        return $map;
    }

    /**
     * The request body becomes the tool's body template only for methods that
     * carry one.
     */
    private function bodyTemplate(IntegrationRequest $request, string $method): ?string
    {
        if (! in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        $body = $request->body_content;

        return is_string($body) && $body !== '' ? $body : null;
    }

    public function execute(ExecuteIntegrationRequestRequest $httpRequest, IntegrationRequest $request): JsonResponse
    {
        $this->authorize('execute', $request->integration);

        $environment = $httpRequest->input('environment_id')
            ? IntegrationEnvironment::find($httpRequest->input('environment_id'))
            : null;

        $result = $this->executor->execute(
            request: $request,
            actor: $httpRequest->user(),
            environment: $environment,
            runtimeVariables: (array) $httpRequest->input('variables', []),
            invokedBy: 'user',
        );

        return response()->json($this->formatResult($result));
    }

    public function executeAdHoc(ExecuteIntegrationRequestRequest $httpRequest, Integration $integration): JsonResponse
    {
        $this->authorize('execute', $integration);

        $environment = $httpRequest->input('environment_id')
            ? IntegrationEnvironment::find($httpRequest->input('environment_id'))
            : null;

        $result = $this->executor->executeAdHoc(
            integration: $integration,
            actor: $httpRequest->user(),
            method: strtoupper($httpRequest->input('method', 'GET')),
            path: $httpRequest->input('path', '/'),
            queryParams: (array) $httpRequest->input('query_params', []),
            headers: (array) $httpRequest->input('headers', []),
            bodyType: $httpRequest->input('body_type'),
            bodyContent: $httpRequest->input('body_content'),
            environment: $environment,
            runtimeVariables: (array) $httpRequest->input('variables', []),
        );

        return response()->json($this->formatResult($result));
    }

    private function present(IntegrationRequest $request): array
    {
        return [
            'id' => $request->id,
            'integration_id' => $request->integration_id,
            'name' => $request->name,
            'description' => $request->description,
            'folder' => $request->folder,
            'method' => $request->method,
            'path' => $request->path,
            'query_params' => $request->query_params ?? [],
            'headers' => $request->headers ?? [],
            'body_type' => $request->body_type,
            'body_content' => $request->body_content,
            'timeout_ms' => $request->timeout_ms,
            'follow_redirects' => $request->follow_redirects,
            'sort_order' => $request->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatResult(IntegrationExecutionResult $result): array
    {
        return [
            'success' => $result->success,
            'status' => $result->status,
            'duration_ms' => $result->durationMs,
            'content_type' => $result->contentType,
            'response_headers' => $result->responseHeaders,
            'response_body' => $result->responseBody,
            'response_size_bytes' => $result->responseSizeBytes,
            'response_truncated' => $result->responseTruncated,
            'error' => $result->error,
            'execution_id' => $result->record?->id,
        ];
    }
}
