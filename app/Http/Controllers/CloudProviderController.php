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
        $user = $request->user();
        $organization = $user->organization;

        [$tenantStorage, $tenantDatabase] = $this->loadTenantProviders($user, $organization);

        $globalStorage = $this->cloudProviderService->getGlobalStorage();
        $globalDatabase = $this->cloudProviderService->getGlobalDatabase();

        return Inertia::render('system/CloudProviders', [
            'canManage' => $this->userCanManage($user, $organization),
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
        $user = $request->user();
        $organization = $user->organization;
        $this->authorizeManage($user, $organization);

        $validated = $this->validateProviderPayload($request, CloudProviderService::KIND_STORAGE);

        $this->upsertTenantProvider(
            $user,
            $organization,
            CloudProviderService::KIND_STORAGE,
            $validated['driver'],
            $validated['credentials'],
        );

        return to_route('system.cloud-providers.index');
    }

    public function storeDatabase(Request $request): RedirectResponse
    {
        $user = $request->user();
        $organization = $user->organization;
        $this->authorizeManage($user, $organization);

        $validated = $this->validateProviderPayload($request, CloudProviderService::KIND_DATABASE);

        // Knowledge-scope wipe only applies to organization-scoped tenant switches.
        // Personal users don't currently own org-scoped KBs, so the impacted list
        // is empty and the wipe is a no-op for them.
        if ($organization) {
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
                    'tenant: workspace database override change by user '.$user->id,
                );
            }
        }

        $this->upsertTenantProvider(
            $user,
            $organization,
            CloudProviderService::KIND_DATABASE,
            $validated['driver'],
            $validated['credentials'],
        );

        return to_route('system.cloud-providers.index');
    }

    public function destroy(Request $request, string $kind): RedirectResponse
    {
        if (! in_array($kind, [CloudProviderService::KIND_STORAGE, CloudProviderService::KIND_DATABASE], true)) {
            abort(404);
        }

        $user = $request->user();
        $organization = $user->organization;
        $this->authorizeManage($user, $organization);

        [$tenantStorage, $tenantDatabase] = $this->loadTenantProviders($user, $organization);
        $provider = $kind === CloudProviderService::KIND_STORAGE ? $tenantStorage : $tenantDatabase;

        // Removing an org-scoped database override means chunks/KBs/docs belonging
        // to this tenant lose their home — decision #5 says delete rather than
        // leave orphans. Personal users aren't wired to this data set yet.
        if ($kind === CloudProviderService::KIND_DATABASE && $provider && $organization) {
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
                    'tenant: workspace database override removed by user '.$user->id,
                );
            }
        }

        $provider?->delete();

        return to_route('system.cloud-providers.index');
    }

    public function testStorage(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $user->organization;
        $this->authorizeManage($user, $organization);

        return response()->json($this->runTest($request, $user, $organization, CloudProviderService::KIND_STORAGE));
    }

    public function testDatabase(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $user->organization;
        $this->authorizeManage($user, $organization);

        return response()->json($this->runTest($request, $user, $organization, CloudProviderService::KIND_DATABASE));
    }

    public function inspectVector(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $user->organization;
        $this->authorizeManage($user, $organization);

        [, $tenantDatabase] = $this->loadTenantProviders($user, $organization);

        if (! $tenantDatabase) {
            return response()->json([
                'configured' => false,
                'message' => __('No workspace database provider configured.'),
            ]);
        }

        return response()->json([
            'configured' => true,
        ] + $this->cloudProviderService->inspectDatabase($tenantDatabase));
    }

    public function installVector(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $user->organization;
        $this->authorizeManage($user, $organization);

        [, $tenantDatabase] = $this->loadTenantProviders($user, $organization);

        if (! $tenantDatabase) {
            return response()->json([
                'success' => false,
                'message' => __('No workspace database provider configured.'),
            ]);
        }

        return response()->json($this->cloudProviderService->installVectorExtension($tenantDatabase));
    }

    /**
     * Load the tenant-scope storage + database providers for the caller. When
     * the user belongs to an organization, the org-scoped rows are returned;
     * when personal, their user-scoped rows are returned instead.
     *
     * @return array{0: ?CloudProvider, 1: ?CloudProvider}
     */
    private function loadTenantProviders(User $user, ?Organization $organization): array
    {
        if ($organization) {
            return [
                $this->cloudProviderService->getTenantStorage($organization),
                $this->cloudProviderService->getTenantDatabase($organization),
            ];
        }

        return [
            $this->cloudProviderService->getPersonalStorage($user),
            $this->cloudProviderService->getPersonalDatabase($user),
        ];
    }

    private function upsertTenantProvider(
        User $user,
        ?Organization $organization,
        string $kind,
        string $driver,
        array $credentials,
    ): CloudProvider {
        if ($organization) {
            return $this->cloudProviderService->upsertTenantProvider(
                $organization,
                $kind,
                $driver,
                $credentials,
                $user->id,
            );
        }

        return $this->cloudProviderService->upsertPersonalProvider(
            $user,
            $kind,
            $driver,
            $credentials,
        );
    }

    private function runTest(Request $request, User $user, ?Organization $organization, string $kind): array
    {
        if ($request->boolean('use_saved')) {
            [$tenantStorage, $tenantDatabase] = $this->loadTenantProviders($user, $organization);
            $provider = $kind === CloudProviderService::KIND_STORAGE ? $tenantStorage : $tenantDatabase;

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

    /**
     * Personal users always manage their own providers. Organization users
     * must be a sysadmin or an active Owner of the organization.
     */
    private function userCanManage(User $user, ?Organization $organization): bool
    {
        if ($user->hasRole('sysadmin')) {
            return true;
        }

        if (! $organization) {
            return true;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('role', MembershipRole::Owner)
            ->where('status', MembershipStatus::Active)
            ->exists();
    }

    private function authorizeManage(User $user, ?Organization $organization): void
    {
        if (! $this->userCanManage($user, $organization)) {
            throw new AuthorizationException(__('Only organization owners can manage cloud providers.'));
        }
    }
}
