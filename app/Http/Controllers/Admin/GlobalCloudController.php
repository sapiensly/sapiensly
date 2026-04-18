<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudProvider;
use App\Services\CloudProviderService;
use App\Services\KnowledgeScopeWiper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GlobalCloudController extends Controller
{
    public function __construct(
        private CloudProviderService $cloudProviderService,
        private KnowledgeScopeWiper $knowledgeScopeWiper,
    ) {}

    public function index(): Response
    {
        $storage = $this->cloudProviderService->getGlobalStorage();
        $database = $this->cloudProviderService->getGlobalDatabase();

        return Inertia::render('admin/GlobalCloud', [
            'drivers' => [
                'storage' => $this->cloudProviderService->getDriverOptions(CloudProviderService::KIND_STORAGE),
                'database' => $this->cloudProviderService->getDriverOptions(CloudProviderService::KIND_DATABASE),
            ],
            'existing' => [
                'storage' => $storage ? $this->presentProvider($storage) : null,
                'database' => $database ? $this->presentProvider($database) : null,
            ],
        ]);
    }

    public function storeStorage(Request $request): RedirectResponse
    {
        $validated = $this->validateProviderPayload($request, CloudProviderService::KIND_STORAGE);

        $this->cloudProviderService->upsertGlobalProvider(
            CloudProviderService::KIND_STORAGE,
            $validated['driver'],
            $validated['credentials'],
        );

        return to_route('admin.system.global-cloud');
    }

    public function storeDatabase(Request $request): RedirectResponse
    {
        $validated = $this->validateProviderPayload($request, CloudProviderService::KIND_DATABASE);

        $impactedOrgIds = $this->knowledgeScopeWiper
            ->impactedOrganizationIdsForDatabaseScope('global');
        $counts = $this->knowledgeScopeWiper->countForOrganizations($impactedOrgIds);

        $hasData = $counts['knowledge_bases'] > 0
            || $counts['documents'] > 0
            || $counts['chunks'] > 0;

        if ($hasData && $request->input('confirm') !== 'DELETE') {
            return back()
                ->withInput()
                ->with('wipe_required', $counts);
        }

        if ($hasData) {
            $this->knowledgeScopeWiper->wipeForOrganizations(
                $impactedOrgIds,
                'admin: global database provider change by user '.$request->user()->id,
            );
        }

        $this->cloudProviderService->upsertGlobalProvider(
            CloudProviderService::KIND_DATABASE,
            $validated['driver'],
            $validated['credentials'],
        );

        return to_route('admin.system.global-cloud');
    }

    public function testStorage(Request $request): JsonResponse
    {
        return response()->json($this->runTest($request, CloudProviderService::KIND_STORAGE));
    }

    public function testDatabase(Request $request): JsonResponse
    {
        return response()->json($this->runTest($request, CloudProviderService::KIND_DATABASE));
    }

    public function inspectVector(): JsonResponse
    {
        $provider = $this->cloudProviderService->getGlobalDatabase();

        if (! $provider) {
            return response()->json([
                'configured' => false,
                'message' => __('No global database provider configured.'),
            ]);
        }

        return response()->json([
            'configured' => true,
        ] + $this->cloudProviderService->inspectDatabase($provider));
    }

    public function installVector(): JsonResponse
    {
        $provider = $this->cloudProviderService->getGlobalDatabase();

        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => __('No global database provider configured.'),
            ]);
        }

        return response()->json($this->cloudProviderService->installVectorExtension($provider));
    }

    /**
     * Run a connection test. If the request includes `use_saved` the existing
     * global provider for that kind is tested; otherwise the raw payload is
     * tested without persisting anything.
     */
    private function runTest(Request $request, string $kind): array
    {
        if ($request->boolean('use_saved')) {
            $provider = $kind === CloudProviderService::KIND_STORAGE
                ? $this->cloudProviderService->getGlobalStorage()
                : $this->cloudProviderService->getGlobalDatabase();

            if (! $provider) {
                return ['success' => false, 'message' => __('No global provider configured for this slot.')];
            }

            return $kind === CloudProviderService::KIND_STORAGE
                ? $this->cloudProviderService->testStorage($provider)
                : $this->cloudProviderService->testDatabase($provider);
        }

        $validated = $this->validateProviderPayload($request, $kind);

        return $kind === CloudProviderService::KIND_STORAGE
            ? $this->cloudProviderService->testStorageForPayload($validated['driver'], $validated['credentials'])
            : $this->cloudProviderService->testDatabaseForPayload($validated['driver'], $validated['credentials']);
    }

    private function validateProviderPayload(Request $request, string $kind): array
    {
        $supportedDrivers = CloudProviderService::DRIVERS_BY_KIND[$kind];
        $driver = (string) $request->input('driver', '');

        $rules = [
            'driver' => ['required', 'string', Rule::in($supportedDrivers)],
            'credentials' => ['required', 'array'],
        ];

        foreach (CloudProviderService::DRIVER_CREDENTIAL_FIELDS[$driver] ?? [] as $field) {
            $optional = in_array($field, CloudProviderService::DRIVER_OPTIONAL_FIELDS[$driver] ?? [], true);
            $rules["credentials.{$field}"] = [
                $optional ? 'nullable' : 'required',
                'string',
                'max:500',
            ];
        }

        return $request->validate($rules);
    }

    private function presentProvider(CloudProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'driver' => $provider->driver,
            'display_name' => $provider->display_name,
            'masked_credentials' => $this->cloudProviderService->maskCredentials($provider->credentials ?? []),
            'status' => $provider->status,
        ];
    }
}
