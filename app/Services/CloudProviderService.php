<?php

namespace App\Services;

use App\Enums\Visibility;
use App\Models\CloudProvider;
use App\Models\Organization;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use PDOException;
use Throwable;

class CloudProviderService
{
    public function __construct(
        private VectorStoreSchema $vectorStoreSchema,
    ) {}

    public const KIND_STORAGE = 'storage';

    public const KIND_DATABASE = 'database';

    /**
     * All supported drivers grouped by kind.
     *
     * @var array<string, array<int, string>>
     */
    public const DRIVERS_BY_KIND = [
        self::KIND_STORAGE => ['s3', 'r2', 'minio', 'digitalocean_spaces'],
        self::KIND_DATABASE => ['postgresql'],
    ];

    /**
     * Human-readable labels per driver.
     */
    public const DRIVER_LABELS = [
        's3' => 'Amazon S3',
        'r2' => 'Cloudflare R2',
        'minio' => 'MinIO',
        'digitalocean_spaces' => 'DigitalOcean Spaces',
        'postgresql' => 'PostgreSQL',
    ];

    /**
     * Credential fields collected from the UI per driver. All fields are required
     * unless listed in DRIVER_OPTIONAL_FIELDS.
     *
     * @var array<string, array<int, string>>
     */
    public const DRIVER_CREDENTIAL_FIELDS = [
        's3' => ['bucket', 'region', 'key', 'secret', 'endpoint', 'url'],
        'r2' => ['bucket', 'region', 'key', 'secret', 'endpoint'],
        'minio' => ['bucket', 'region', 'key', 'secret', 'endpoint'],
        'digitalocean_spaces' => ['bucket', 'region', 'key', 'secret', 'endpoint'],
        'postgresql' => ['host', 'port', 'database', 'username', 'password', 'sslmode'],
    ];

    /**
     * Fields that are optional for a given driver.
     *
     * @var array<string, array<int, string>>
     */
    public const DRIVER_OPTIONAL_FIELDS = [
        's3' => ['endpoint', 'url'],
        'r2' => [],
        'minio' => [],
        'digitalocean_spaces' => [],
        'postgresql' => ['port', 'sslmode'],
    ];

    /**
     * Credential field names that must be masked when exposed to the UI.
     *
     * @var array<int, string>
     */
    public const SENSITIVE_FIELDS = ['secret', 'password', 'key'];

    /**
     * Runtime connection name used when binding a resolved database provider
     * into Laravel's connection manager.
     */
    public const RUNTIME_DB_CONNECTION = 'tenant_custom';

    // =========================================================================
    // Resolution (tenant → global → null)
    // =========================================================================

    public function resolveStorage(?Organization $organization): ?CloudProvider
    {
        return $this->resolve(self::KIND_STORAGE, $organization);
    }

    public function resolveDatabase(?Organization $organization): ?CloudProvider
    {
        return $this->resolve(self::KIND_DATABASE, $organization);
    }

    public function getGlobalStorage(): ?CloudProvider
    {
        return $this->getGlobal(self::KIND_STORAGE);
    }

    public function getGlobalDatabase(): ?CloudProvider
    {
        return $this->getGlobal(self::KIND_DATABASE);
    }

    public function getTenantStorage(Organization $organization): ?CloudProvider
    {
        return $this->getTenant(self::KIND_STORAGE, $organization);
    }

    public function getTenantDatabase(Organization $organization): ?CloudProvider
    {
        return $this->getTenant(self::KIND_DATABASE, $organization);
    }

    public function getPersonalStorage(User $user): ?CloudProvider
    {
        return $this->getPersonal(self::KIND_STORAGE, $user);
    }

    public function getPersonalDatabase(User $user): ?CloudProvider
    {
        return $this->getPersonal(self::KIND_DATABASE, $user);
    }

    private function resolve(string $kind, ?Organization $organization): ?CloudProvider
    {
        if ($organization !== null) {
            $tenant = $this->getTenant($kind, $organization);
            if ($tenant !== null) {
                return $tenant;
            }
        }

        return $this->getGlobal($kind);
    }

    private function getTenant(string $kind, Organization $organization): ?CloudProvider
    {
        return CloudProvider::query()
            ->where('organization_id', $organization->id)
            ->where('kind', $kind)
            ->where('status', 'active')
            ->whereIn('visibility', [Visibility::Organization, Visibility::Private])
            ->orderByDesc('is_default')
            ->first();
    }

    private function getGlobal(string $kind): ?CloudProvider
    {
        return CloudProvider::query()
            ->where('visibility', Visibility::Global)
            ->where('kind', $kind)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->first();
    }

    private function getPersonal(string $kind, User $user): ?CloudProvider
    {
        return CloudProvider::query()
            ->whereNull('organization_id')
            ->where('user_id', $user->id)
            ->where('visibility', Visibility::Private)
            ->where('kind', $kind)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->first();
    }

    // =========================================================================
    // Persistence
    // =========================================================================

    /**
     * Replace the global provider for a given kind with fresh driver + credentials.
     * Only one global provider exists per kind at a time.
     */
    public function upsertGlobalProvider(string $kind, string $driver, array $credentials): CloudProvider
    {
        $this->assertDriverSupported($kind, $driver);

        $existing = CloudProvider::query()
            ->where('visibility', Visibility::Global)
            ->where('kind', $kind)
            ->first();

        $attributes = [
            'driver' => $driver,
            'display_name' => self::DRIVER_LABELS[$driver] ?? $driver,
            'credentials' => $credentials,
            'is_default' => true,
            'status' => 'active',
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return CloudProvider::create($attributes + [
            'user_id' => null,
            'organization_id' => null,
            'visibility' => Visibility::Global,
            'kind' => $kind,
        ]);
    }

    /**
     * Replace the tenant-scoped provider for a given kind with fresh driver + credentials.
     */
    public function upsertTenantProvider(
        Organization $organization,
        string $kind,
        string $driver,
        array $credentials,
        int $userId,
    ): CloudProvider {
        $this->assertDriverSupported($kind, $driver);

        $existing = CloudProvider::query()
            ->where('organization_id', $organization->id)
            ->where('kind', $kind)
            ->first();

        $attributes = [
            'driver' => $driver,
            'display_name' => self::DRIVER_LABELS[$driver] ?? $driver,
            'credentials' => $credentials,
            'is_default' => true,
            'status' => 'active',
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return CloudProvider::create($attributes + [
            'user_id' => $userId,
            'organization_id' => $organization->id,
            'visibility' => Visibility::Organization,
            'kind' => $kind,
        ]);
    }

    /**
     * Replace the personal (user-scoped) provider for a given kind. Personal
     * providers have no organization — they belong to a single user and use
     * the Private visibility. Only one personal provider exists per user+kind.
     */
    public function upsertPersonalProvider(
        User $user,
        string $kind,
        string $driver,
        array $credentials,
    ): CloudProvider {
        $this->assertDriverSupported($kind, $driver);

        $existing = CloudProvider::query()
            ->whereNull('organization_id')
            ->where('user_id', $user->id)
            ->where('visibility', Visibility::Private)
            ->where('kind', $kind)
            ->first();

        $attributes = [
            'driver' => $driver,
            'display_name' => self::DRIVER_LABELS[$driver] ?? $driver,
            'credentials' => $credentials,
            'is_default' => true,
            'status' => 'active',
        ];

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return CloudProvider::create($attributes + [
            'user_id' => $user->id,
            'organization_id' => null,
            'visibility' => Visibility::Private,
            'kind' => $kind,
        ]);
    }

    // =========================================================================
    // Runtime disk / connection builders
    // =========================================================================

    /**
     * Resolve a filesystem disk for the given organization. Returns null when no
     * global or tenant storage provider is configured; callers may fall back to
     * the legacy env-based 'documents' disk.
     */
    public function diskForTenant(?Organization $organization): ?Filesystem
    {
        $provider = $this->resolveStorage($organization);

        return $provider ? $this->buildDisk($provider) : null;
    }

    /**
     * Resolve a filesystem disk for the given organization id, falling back to
     * the legacy env-based 'documents' disk when no tenant nor global provider
     * is configured. This is the primary entry point for call-sites that used
     * to hardcode Storage::disk('documents').
     */
    public function diskForOrganizationOrFallback(?string $organizationId): Filesystem
    {
        $organization = $organizationId ? Organization::find($organizationId) : null;

        return $this->diskForTenant($organization) ?? Storage::disk('documents');
    }

    public function buildDisk(CloudProvider $provider): Filesystem
    {
        if ($provider->kind !== self::KIND_STORAGE) {
            throw new \InvalidArgumentException("Provider {$provider->id} is not a storage provider.");
        }

        $credentials = $provider->credentials ?? [];

        return Storage::build([
            'driver' => 's3',
            'key' => $credentials['key'] ?? '',
            'secret' => $credentials['secret'] ?? '',
            'region' => $credentials['region'] ?? 'us-east-1',
            'bucket' => $credentials['bucket'] ?? '',
            'endpoint' => $credentials['endpoint'] ?? null,
            'url' => $credentials['url'] ?? null,
            'use_path_style_endpoint' => $this->usesPathStyleEndpoint($provider->driver),
            'visibility' => 'private',
            'throw' => true,
        ]);
    }

    /**
     * Resolve a database connection for the given organization and register it
     * as Laravel's RUNTIME_DB_CONNECTION. Returns null when no provider is
     * configured at tenant or global scope.
     */
    public function connectionForTenant(?Organization $organization): ?Connection
    {
        $provider = $this->resolveDatabase($organization);

        return $provider ? $this->buildConnection($provider) : null;
    }

    /**
     * Return the database connection that custom-table and vector-store queries
     * should target for the given user. If the user's organization has a
     * tenant-scoped database provider, or if a global one is configured, that
     * provider's connection is built and returned under the RUNTIME_DB_CONNECTION
     * name. Otherwise, the application's default connection is returned — so
     * callers can use the same method in every environment without branching.
     */
    public function tenantConnectionFor(?User $user): Connection
    {
        $organization = $user?->organization;
        $provider = $this->resolveDatabase($organization);

        if ($provider === null) {
            return DB::connection();
        }

        return $this->buildConnection($provider);
    }

    public function buildConnection(CloudProvider $provider): Connection
    {
        if ($provider->kind !== self::KIND_DATABASE) {
            throw new \InvalidArgumentException("Provider {$provider->id} is not a database provider.");
        }

        $credentials = $provider->credentials ?? [];

        Config::set('database.connections.'.self::RUNTIME_DB_CONNECTION, [
            'driver' => 'pgsql',
            'host' => $credentials['host'] ?? '127.0.0.1',
            'port' => (int) ($credentials['port'] ?? 5432),
            'database' => $credentials['database'] ?? '',
            'username' => $credentials['username'] ?? '',
            'password' => $credentials['password'] ?? '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => $credentials['sslmode'] ?? 'prefer',
        ]);

        DB::purge(self::RUNTIME_DB_CONNECTION);

        return DB::connection(self::RUNTIME_DB_CONNECTION);
    }

    // =========================================================================
    // Connection testing
    // =========================================================================

    /**
     * @return array{success: bool, message: string, detail?: string}
     */
    public function testStorage(CloudProvider $provider): array
    {
        return $this->testStorageForPayload($provider->driver, $provider->credentials ?? []);
    }

    /**
     * @return array{success: bool, message: string, detail?: string}
     */
    public function testStorageForPayload(string $driver, array $credentials): array
    {
        $bucket = $credentials['bucket'] ?? '';
        if ($bucket === '') {
            return ['success' => false, 'message' => __('Bucket is required.')];
        }

        try {
            $client = new S3Client([
                'version' => 'latest',
                'region' => $credentials['region'] ?? 'us-east-1',
                'endpoint' => $credentials['endpoint'] ?? null,
                'use_path_style_endpoint' => $this->usesPathStyleEndpoint($driver),
                'credentials' => [
                    'key' => $credentials['key'] ?? '',
                    'secret' => $credentials['secret'] ?? '',
                ],
                'http' => ['timeout' => 10, 'connect_timeout' => 5],
            ]);

            $client->headBucket(['Bucket' => $bucket]);

            return ['success' => true, 'message' => __('Connection successful.')];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => __('Connection failed.'),
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, message: string, detail?: string}
     */
    public function testDatabase(CloudProvider $provider): array
    {
        return $this->testDatabaseForPayload($provider->driver, $provider->credentials ?? []);
    }

    /**
     * @return array{success: bool, message: string, detail?: string}
     */
    public function testDatabaseForPayload(string $driver, array $credentials): array
    {
        if ($driver !== 'postgresql') {
            return ['success' => false, 'message' => __('Unsupported database driver.')];
        }

        $host = $credentials['host'] ?? '';
        $database = $credentials['database'] ?? '';
        if ($host === '' || $database === '') {
            return ['success' => false, 'message' => __('Host and database are required.')];
        }

        $port = (int) ($credentials['port'] ?? 5432);
        $sslmode = $credentials['sslmode'] ?? 'prefer';

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $host,
            $port,
            $database,
            $sslmode,
        );

        try {
            $pdo = new PDO($dsn, $credentials['username'] ?? '', $credentials['password'] ?? '', [
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->query('SELECT 1');

            return ['success' => true, 'message' => __('Connection successful.')];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => __('Connection failed.'),
                'detail' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Vector store bootstrapping
    // =========================================================================

    /**
     * Inspect the current vector-store state on the database behind $provider:
     * whether pgvector is installed and whether the chunks table exists. When
     * the target database is unreachable the call degrades gracefully with
     * `reachable=false` so UIs can surface a credential/network error without
     * 500-ing the request.
     *
     * @return array{reachable: bool, driver: string, has_extension: bool, has_schema: bool, error?: string}
     */
    public function inspectDatabase(CloudProvider $provider): array
    {
        $connection = $this->buildConnection($provider);

        try {
            $hasSchema = $this->vectorStoreSchema->hasSchema($connection);

            return [
                'reachable' => true,
                'driver' => $connection->getDriverName(),
                'has_extension' => $this->vectorStoreSchema->hasExtension($connection),
                'has_schema' => $hasSchema,
                'chunk_count' => $hasSchema
                    ? $connection->table(VectorStoreSchema::TABLE)->count()
                    : 0,
            ];
        } catch (Throwable $e) {
            return [
                'reachable' => false,
                'driver' => $provider->driver,
                'has_extension' => false,
                'has_schema' => false,
                'chunk_count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Install pgvector on $provider's database. Returns the raw install result
     * plus a post-install inspection so the UI can update its state in one
     * round-trip.
     *
     * @return array{success: bool, message: string, detail?: string, instructions?: string, state: array{has_extension: bool, has_schema: bool, driver: string}}
     */
    public function installVectorExtension(CloudProvider $provider): array
    {
        $connection = $this->buildConnection($provider);

        try {
            $result = $this->vectorStoreSchema->installExtension($connection);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => __('Could not reach the database.'),
                'detail' => $e->getMessage(),
                'state' => [
                    'reachable' => false,
                    'driver' => $provider->driver,
                    'has_extension' => false,
                    'has_schema' => false,
                ],
            ];
        }

        if ($result['success']) {
            try {
                $this->vectorStoreSchema->ensureSchema($connection);
            } catch (Throwable $e) {
                // Extension installed but schema bootstrap failed: surface it
                // without flipping success to false, since the extension part
                // worked and the admin can retry bootstrapping.
                $result['schema_error'] = $e->getMessage();
            }
        }

        $result['state'] = $this->inspectDatabase($provider);

        return $result;
    }

    /**
     * Try to bootstrap the vector-store schema on $provider's database. Safe
     * to call after every save: it is idempotent and skips the table creation
     * if it already exists.
     *
     * @return array{success: bool, message: string, detail?: string}
     */
    public function bootstrapVectorSchema(CloudProvider $provider): array
    {
        $connection = $this->buildConnection($provider);

        if (! $this->vectorStoreSchema->hasExtension($connection)) {
            return [
                'success' => false,
                'message' => __('pgvector extension is not installed on this database.'),
            ];
        }

        try {
            $this->vectorStoreSchema->ensureSchema($connection);

            return [
                'success' => true,
                'message' => __('Vector store schema ready.'),
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => __('Failed to bootstrap the vector store schema.'),
                'detail' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Metadata for UI
    // =========================================================================

    /**
     * Driver options for a given kind, including credential fields needed by the form.
     *
     * @return array<int, array{value: string, label: string, credential_fields: array<int, string>, optional_fields: array<int, string>}>
     */
    public function getDriverOptions(string $kind): array
    {
        return collect(self::DRIVERS_BY_KIND[$kind] ?? [])
            ->map(fn (string $driver) => [
                'value' => $driver,
                'label' => self::DRIVER_LABELS[$driver] ?? $driver,
                'credential_fields' => self::DRIVER_CREDENTIAL_FIELDS[$driver] ?? [],
                'optional_fields' => self::DRIVER_OPTIONAL_FIELDS[$driver] ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * Mask sensitive credential fields for safe exposure to the UI.
     */
    public function maskCredentials(array $credentials): array
    {
        $masked = [];
        foreach ($credentials as $key => $value) {
            if (in_array($key, self::SENSITIVE_FIELDS, true) && is_string($value) && strlen($value) > 0) {
                $masked[$key] = $this->maskValue($value);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    private function maskValue(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('•', strlen($value));
        }

        return substr($value, 0, 4).'...'.substr($value, -4);
    }

    private function usesPathStyleEndpoint(string $driver): bool
    {
        return in_array($driver, ['minio'], true);
    }

    private function assertDriverSupported(string $kind, string $driver): void
    {
        $supported = self::DRIVERS_BY_KIND[$kind] ?? [];
        if (! in_array($driver, $supported, true)) {
            throw new \InvalidArgumentException("Driver '{$driver}' is not supported for kind '{$kind}'.");
        }
    }
}
