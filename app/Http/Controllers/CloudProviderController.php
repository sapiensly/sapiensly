<?php

namespace App\Http\Controllers;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\CloudProvider;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\CloudProviderService;
use App\Services\KnowledgeScopeWiper;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CloudProviderController extends Controller
{
    public function __construct(
        private CloudProviderService $cloudProviderService,
        private KnowledgeScopeWiper $knowledgeScopeWiper,
    ) {}

    public function index(Request $request): Response
    {
        $organization = $this->requireOrganization($request->user());

        $tenantStorage = $this->cloudProviderService->getTenantStorage($organization);
        $tenantDatabase = $this->cloudProviderService->getTenantDatabase($organization);
        $globalStorage = $this->cloudProviderService->getGlobalStorage();
        $globalDatabase = $this->cloudProviderService->getGlobalDatabase();

        return Inertia::render('system/CloudProviders', [
            'canManage' => $this->userCanManage($request->user(), $organization),
            'drivers' => [
                'storage' => $this->cloudProviderService->getDriverOptions(CloudProviderService::KIND_STORAGE),
                'database' => $this->cloudProviderService->getDriverOptions(CloudProviderService::KIND_DATABASE),
            ],
            'tenant' => [
                'storage' => $tenantStorage ? $this->presentProvider($tenantStorage) : null,
                'database' => $tenantDatabase ? $this->presentProvider($tenantDatabase) : null,
            ],
            'global' => [
                'storage' => $globalStorage ? $this->presentProvider($globalStorage) : null,
                'database' => $globalDatabase ? $this->presentProvider($globalDatabase) : null,
            ],
        ]);
    }

    public function storeStorage(Request $request): RedirectResponse
    {
        $organization = $this->requireOrganization($request->user());
        $this->authorizeManage($request->user(), $organization);

        $validated = $this->validateProviderPayload($request, CloudProviderService::KIND_STORAGE);

        $this->cloudProviderService->upsertTenantProvider(
            $organization,
            CloudProviderService::KIND_STORAGE,
            $validated['driver'],
            $validated['credentials'],
            $request->user()->id,
        );

        return to_route('system.cloud-providers.index');
    }

    public function storeDatabase(Request $request): RedirectResponse
    {
        $organization = $this->requireOrganization($request->user());
        $this->authorizeManage($request->user(), $organization);

        $validated = $this->validateProviderPayload($request, CloudProviderService::KIND_DATABASE);

        $impactedOrgIds = $this->knowledgeScopeWiper
            ->impactedOrganizationIdsForDatabaseScope('tenant', $organization);
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
                'tenant: workspace database override change by user '.$request->user()->id,
            );
        }

        $this->cloudProviderService->upsertTenantProvider(
            $organization,
            CloudProviderService::KIND_DATABASE,
            $validated['driver'],
            $validated['credentials'],
            $request->user()->id,
        );

        return to_route('system.cloud-providers.index');
    }

    public function destroy(Request $request, string $kind): RedirectResponse
    {
        if (! in_array($kind, [CloudProviderService::KIND_STORAGE, CloudProviderService::KIND_DATABASE], true)) {
            abort(404);
        }

        $organization = $this->requireOrganization($request->user());
        $this->authorizeManage($request->user(), $organization);

        $provider = $kind === CloudProviderService::KIND_STORAGE
            ? $this->cloudProviderService->getTenantStorage($organization)
            : $this->cloudProviderService->getTenantDatabase($organization);

        // Removing a tenant database override means chunks/KBs/docs belonging
        // to this tenant lose their home — decision #5 says delete rather
        // than leave orphans.
        if ($kind === CloudProviderService::KIND_DATABASE && $provider) {
            $impactedOrgIds = $this->knowledgeScopeWiper
                ->impactedOrganizationIdsForDatabaseScope('tenant', $organization);
            $counts = $this->knowledgeScopeWiper->countForOrganizations($impactedOrgIds);

            $hasData = $counts['knowledge_bases'] > 0
                || $counts['documents'] > 0
                || $counts['chunks'] > 0;

            if ($hasData && $request->input('confirm') !== 'DELETE') {
                return back()->with('wipe_required', $counts);
            }

            if ($hasData) {
                $this->knowledgeScopeWiper->wipeForOrganizations(
                    $impactedOrgIds,
                    'tenant: workspace database override removed by user '.$request->user()->id,
                );
            }
        }

        $provider?->delete();

        return to_route('system.cloud-providers.index');
    }

    public function testStorage(Request $request): JsonResponse
    {
        $organization = $this->requireOrganization($request->user());
        $this->authorizeManage($request->user(), $organization);

        return response()->json($this->runTest($request, $organization, CloudProviderService::KIND_STORAGE));
    }

    public function testDatabase(Request $request): JsonResponse
    {
        $organization = $this->requireOrganization($request->user());
        $this->authorizeManage($request->user(), $organization);

        return response()->json($this->runTest($request, $organization, CloudProviderService::KIND_DATABASE));
    }

    public function inspectVector(Request $request): JsonResponse
    {
        $organization = $this->requireOrganization($request->user());
        $this->authorizeManage($request->user(), $organization);

        $provider = $this->cloudProviderService->getTenantDatabase($organization);

        if (! $provider) {
            return response()->json([
                'configured' => false,
                'message' => __('No workspace database provider configured.'),
            ]);
        }

        return response()->json([
            'configured' => true,
        ] + $this->cloudProviderService->inspectDatabase($provider));
    }

    public function installVector(Request $request): JsonResponse
    {
        $organization = $this->requireOrganization($request->user());
        $this->authorizeManage($request->user(), $organization);

        $provider = $this->cloudProviderService->getTenantDatabase($organization);

        if (! $provider) {
            return response()->json([
                'success' => false,
                'message' => __('No workspace database provider configured.'),
            ]);
        }

        return response()->json($this->cloudProviderService->installVectorExtension($provider));
    }

    private function runTest(Request $request, Organization $organization, string $kind): array
    {
        if ($request->boolean('use_saved')) {
            $provider = $kind === CloudProviderService::KIND_STORAGE
                ? $this->cloudProviderService->getTenantStorage($organization)
                : $this->cloudProviderService->getTenantDatabase($organization);

            if (! $provider) {
                return ['success' => false, 'message' => __('No tenant provider configured for this slot.')];
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

    private function requireOrganization(User $user): Organization
    {
        if (! $user->organization_id) {
            abort(403, __('You must belong to an organization to manage cloud providers.'));
        }

        $organization = $user->organization;
        if (! $organization) {
            abort(403, __('Organization not found.'));
        }

        return $organization;
    }

    private function userCanManage(User $user, Organization $organization): bool
    {
        if ($user->hasRole('sysadmin')) {
            return true;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('role', MembershipRole::Owner)
            ->where('status', MembershipStatus::Active)
            ->exists();
    }

    private function authorizeManage(User $user, Organization $organization): void
    {
        if (! $this->userCanManage($user, $organization)) {
            throw new AuthorizationException(__('Only organization owners can manage cloud providers.'));
        }
    }
}
