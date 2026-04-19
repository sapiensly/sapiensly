<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationAuthType;
use App\Enums\Visibility;
use App\Models\Integration;
use App\Models\User;
use App\Services\Integrations\Support\SsrfGuard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class IntegrationService
{
    public function __construct(
        private IntegrationRequestExecutor $executor,
        private SsrfGuard $ssrfGuard,
    ) {}

    public function listForUser(User $user, ?string $search = null, ?string $authType = null): Collection
    {
        $query = Integration::query()
            ->where(function ($q) use ($user) {
                $q->where('visibility', Visibility::Global);
                if ($user->organization_id) {
                    $q->orWhere(function ($inner) use ($user) {
                        $inner->where('organization_id', $user->organization_id)
                            ->whereIn('visibility', [Visibility::Organization, Visibility::Private]);
                    });
                } else {
                    $q->orWhere(function ($inner) use ($user) {
                        $inner->where('user_id', $user->id)->whereNull('organization_id');
                    });
                }
            });

        if ($search) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('base_url', 'like', "%{$search}%"));
        }

        if ($authType) {
            $query->where('auth_type', $authType);
        }

        return $query->orderBy('name')->get();
    }

    public function create(User $user, array $attributes): Integration
    {
        return DB::transaction(function () use ($user, $attributes) {
            $visibility = $attributes['visibility'] ?? Visibility::Private->value;

            $integration = Integration::create([
                'user_id' => $visibility === Visibility::Global->value ? null : $user->id,
                'organization_id' => $visibility === Visibility::Organization->value ? $user->organization_id : null,
                'visibility' => $visibility,
                'name' => $attributes['name'],
                'slug' => $attributes['slug'] ?? $this->buildSlug($user, $attributes['name']),
                'description' => $attributes['description'] ?? null,
                'base_url' => rtrim($attributes['base_url'], '/'),
                'auth_type' => $attributes['auth_type'] ?? IntegrationAuthType::None->value,
                'auth_config' => $attributes['auth_config'] ?? [],
                'default_headers' => $attributes['default_headers'] ?? null,
                'status' => $attributes['status'] ?? 'active',
                'color' => $attributes['color'] ?? null,
                'icon' => $attributes['icon'] ?? null,
                'allow_insecure_tls' => $attributes['allow_insecure_tls'] ?? false,
            ]);

            return $integration->fresh();
        });
    }

    public function update(Integration $integration, array $attributes): Integration
    {
        $fillable = [
            'name', 'slug', 'description', 'base_url', 'auth_type',
            'default_headers', 'status', 'color', 'icon', 'allow_insecure_tls',
            'active_environment_id',
        ];

        $patch = array_intersect_key($attributes, array_flip($fillable));

        if (isset($patch['base_url'])) {
            $patch['base_url'] = rtrim($patch['base_url'], '/');
        }

        // Merge auth_config if supplied; empty strings keep existing value so
        // the user doesn't need to re-type secrets during an edit.
        if (array_key_exists('auth_config', $attributes)) {
            $current = $integration->auth_config ?? [];
            $new = $attributes['auth_config'] ?? [];
            foreach ($new as $key => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $current[$key] = $value;
            }
            $patch['auth_config'] = $current;
        }

        $integration->update($patch);

        return $integration->fresh();
    }

    public function delete(Integration $integration): void
    {
        $integration->delete();
    }

    public function duplicate(Integration $integration, User $user): Integration
    {
        return DB::transaction(function () use ($integration, $user) {
            $copy = $integration->replicate(['last_tested_at', 'last_test_status', 'last_test_message']);
            $copy->name = $integration->name.' (copy)';
            $copy->slug = $this->buildSlug($user, $copy->name);
            $copy->user_id = $user->id;
            $copy->visibility = $user->organization_id
                ? Visibility::Organization
                : Visibility::Private;
            $copy->organization_id = $user->organization_id;
            $copy->push();

            foreach ($integration->environments as $env) {
                $newEnv = $env->replicate();
                $newEnv->integration_id = $copy->id;
                $newEnv->push();

                foreach ($env->variables as $var) {
                    $newVar = $var->replicate();
                    $newVar->integration_environment_id = $newEnv->id;
                    $newVar->push();
                }
            }

            foreach ($integration->requests as $req) {
                $newReq = $req->replicate();
                $newReq->integration_id = $copy->id;
                $newReq->push();
            }

            return $copy->fresh();
        });
    }

    /**
     * Test the connection by issuing a HEAD (fallback GET) against base_url
     * with auth applied, through the same executor pipeline.
     *
     * @return array{success: bool, message: string, detail?: string}
     */
    public function testConnection(Integration $integration): array
    {
        try {
            $result = $this->executor->executeAdHoc(
                integration: $integration,
                actor: null,
                method: 'GET',
                path: '/',
                queryParams: [],
                headers: [],
                bodyType: null,
                bodyContent: null,
            );

            $integration->update([
                'last_tested_at' => now(),
                'last_test_status' => $result->success ? 'success' : 'failure',
                'last_test_message' => $result->success
                    ? __('Connection successful.')
                    : ($result->error ?? __('Connection failed.')),
            ]);

            if ($result->success) {
                return ['success' => true, 'message' => __('Connection successful.')];
            }

            return [
                'success' => false,
                'message' => __('Connection failed: :status', ['status' => $result->status ?? 'network']),
                'detail' => $result->error ?? (string) $result->responseBody,
            ];
        } catch (Throwable $e) {
            $integration->update([
                'last_tested_at' => now(),
                'last_test_status' => 'failure',
                'last_test_message' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => __('Connection failed.'),
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, string>
     */
    public function maskAuthConfig(Integration $integration): array
    {
        $masked = [];
        $sensitive = ['value', 'token', 'password', 'client_secret', 'access_token', 'refresh_token'];

        foreach ($integration->auth_config ?? [] as $key => $value) {
            if (in_array($key, $sensitive, true) && is_string($value) && $value !== '') {
                $masked[$key] = strlen($value) > 8
                    ? substr($value, 0, 4).'...'.substr($value, -4)
                    : str_repeat('•', strlen($value));

                continue;
            }
            $masked[$key] = $value;
        }

        return $masked;
    }

    private function buildSlug(User $user, string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'integration';
        }

        $scope = $user->organization_id ?: $user->id;
        $slug = $base;
        $i = 1;
        while (Integration::withTrashed()
            ->where('organization_id', $user->organization_id)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
