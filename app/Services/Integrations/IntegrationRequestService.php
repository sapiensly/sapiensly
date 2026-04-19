<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\IntegrationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class IntegrationRequestService
{
    public function create(Integration $integration, User $user, array $attributes): IntegrationRequest
    {
        return DB::transaction(function () use ($integration, $user, $attributes) {
            $sortOrder = (int) $integration->requests()->max('sort_order') + 1;

            return IntegrationRequest::create([
                'integration_id' => $integration->id,
                'user_id' => $user->id,
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'folder' => $attributes['folder'] ?? null,
                'method' => strtoupper($attributes['method'] ?? 'GET'),
                'path' => $attributes['path'] ?? '/',
                'query_params' => $attributes['query_params'] ?? [],
                'headers' => $attributes['headers'] ?? [],
                'body_type' => $attributes['body_type'] ?? null,
                'body_content' => $attributes['body_content'] ?? null,
                'timeout_ms' => min(
                    (int) ($attributes['timeout_ms'] ?? 30_000),
                    (int) config('integrations.max_timeout_ms', 30_000),
                ),
                'follow_redirects' => $attributes['follow_redirects'] ?? true,
                'sort_order' => $attributes['sort_order'] ?? $sortOrder,
            ]);
        });
    }

    public function update(IntegrationRequest $request, array $attributes): IntegrationRequest
    {
        $fillable = [
            'name', 'description', 'folder', 'method', 'path', 'query_params',
            'headers', 'body_type', 'body_content', 'timeout_ms',
            'follow_redirects', 'sort_order',
        ];

        $patch = array_intersect_key($attributes, array_flip($fillable));
        if (isset($patch['method'])) {
            $patch['method'] = strtoupper($patch['method']);
        }
        if (isset($patch['timeout_ms'])) {
            $patch['timeout_ms'] = min(
                (int) $patch['timeout_ms'],
                (int) config('integrations.max_timeout_ms', 30_000),
            );
        }

        $request->update($patch);

        return $request->fresh();
    }

    public function delete(IntegrationRequest $request): void
    {
        $request->delete();
    }

    public function duplicate(IntegrationRequest $request, User $user): IntegrationRequest
    {
        $copy = $request->replicate();
        $copy->name = $request->name.' (copy)';
        $copy->user_id = $user->id;
        $copy->sort_order = (int) $request->integration->requests()->max('sort_order') + 1;
        $copy->push();

        return $copy->fresh();
    }
}
