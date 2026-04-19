<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\IntegrationExecution;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationExecutionController extends Controller
{
    public function index(Request $request, Integration $integration): Response
    {
        $this->authorize('view', $integration);

        $executions = $integration->executions()
            ->when($request->filled('status'), fn ($q) => $q->where('success', $request->boolean('status')))
            ->when($request->filled('request_id'), fn ($q) => $q->where('integration_request_id', $request->input('request_id')))
            ->latest('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('system/integrations/Executions', [
            'integration' => [
                'id' => $integration->id,
                'name' => $integration->name,
            ],
            'executions' => $executions->map(fn (IntegrationExecution $e) => [
                'id' => $e->id,
                'method' => $e->method,
                'url' => $e->url,
                'status' => $e->response_status,
                'success' => $e->success,
                'duration_ms' => $e->duration_ms,
                'created_at' => $e->created_at?->toIso8601String(),
                'integration_request_id' => $e->integration_request_id,
            ]),
            'filters' => [
                'status' => $request->input('status'),
                'request_id' => $request->input('request_id'),
            ],
        ]);
    }

    public function show(IntegrationExecution $execution): Response
    {
        $this->authorize('view', $execution->integration);

        return Inertia::render('system/integrations/ExecutionDetail', [
            'execution' => [
                'id' => $execution->id,
                'integration_id' => $execution->integration_id,
                'integration_request_id' => $execution->integration_request_id,
                'method' => $execution->method,
                'url' => $execution->url,
                'request_headers' => $execution->request_headers,
                'request_body' => $execution->request_body,
                'response_status' => $execution->response_status,
                'response_headers' => $execution->response_headers,
                'response_body' => $execution->response_body,
                'response_size_bytes' => $execution->response_size_bytes,
                'duration_ms' => $execution->duration_ms,
                'success' => $execution->success,
                'error_message' => $execution->error_message,
                'metadata' => $execution->metadata,
                'created_at' => $execution->created_at?->toIso8601String(),
            ],
        ]);
    }
}
