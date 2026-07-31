<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformProbe;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin v2 Stack — "what's this platform made of" dashboard. Pulls versions
 * straight from composer.lock / package.json (no composer-outdated roundtrip
 * on each page load) and supplements with live probes for Horizon, Reverb,
 * Redis, and Postgres. Read-only.
 *
 * Returns the `StackProps` contract defined in `lib/admin/types.ts`: five
 * ordered groups (runtime / frontend / data / ai / infra), each with rows
 * carrying a status dot ('ok' | 'outdated' | 'missing').
 *
 * The probes and version lookups live in {@see PlatformProbe}, shared with the
 * `platform_stack` / `platform_health` MCP tools.
 */
class AdminStackController extends Controller
{
    public function __construct(
        private readonly PlatformProbe $probe,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/Stack', [
            'groups' => [
                $this->runtimeGroup(),
                $this->frontendGroup(),
                $this->dataGroup(),
                $this->aiGroup(),
                $this->infraGroup(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeGroup(): array
    {
        return [
            'id' => 'runtime',
            'label' => __('Runtime'),
            'items' => [
                $this->item(
                    name: 'PHP',
                    version: PHP_VERSION,
                    description: __('Application interpreter — runs every request.'),
                    docsUrl: 'https://www.php.net/',
                ),
                $this->item(
                    name: 'Laravel',
                    version: Application::VERSION,
                    description: __('Framework — HTTP routing, queues, ORM, broadcasting.'),
                    docsUrl: 'https://laravel.com/docs',
                ),
                $this->item(
                    name: 'Inertia',
                    version: $this->probe->composerVersion('inertiajs/inertia-laravel'),
                    description: __('Server-rendered SPA bridge between Laravel and Vue.'),
                    docsUrl: 'https://inertiajs.com/',
                ),
                $this->item(
                    name: 'Fortify',
                    version: $this->probe->composerVersion('laravel/fortify'),
                    description: __('Backend auth, 2FA, email verification, password reset.'),
                    docsUrl: 'https://laravel.com/docs/fortify',
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function frontendGroup(): array
    {
        return [
            'id' => 'frontend',
            'label' => __('Frontend'),
            'items' => [
                $this->item(
                    name: 'Vue',
                    version: $this->probe->npmVersion('vue'),
                    description: __('UI framework — every admin screen is a Vue SFC.'),
                    docsUrl: 'https://vuejs.org/',
                ),
                $this->item(
                    name: 'TypeScript',
                    version: $this->probe->npmVersion('typescript'),
                    description: __('Static types for the frontend.'),
                    docsUrl: 'https://www.typescriptlang.org/',
                ),
                $this->item(
                    name: 'Tailwind CSS',
                    version: $this->probe->npmVersion('tailwindcss'),
                    description: __('Utility-first styling with the brand token block.'),
                    docsUrl: 'https://tailwindcss.com/',
                ),
                $this->item(
                    name: 'reka-ui',
                    version: $this->probe->npmVersion('reka-ui'),
                    description: __('Accessible primitives under every shadcn-vue component.'),
                    docsUrl: 'https://reka-ui.com/',
                ),
                $this->item(
                    name: 'Vite',
                    version: $this->probe->npmVersion('vite'),
                    description: __('Dev server + production bundler.'),
                    docsUrl: 'https://vitejs.dev/',
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dataGroup(): array
    {
        // The actual driver of the default connection — NOT config('database.default'),
        // which is the connection NAME (`platform` since the schema split), not a driver.
        $dbDriver = DB::connection()->getDriverName();
        $database = $this->probe->database();

        return [
            'id' => 'data',
            'label' => __('Data'),
            'items' => [
                $this->item(
                    name: 'PostgreSQL',
                    version: $database['version'] ?? 'unknown',
                    description: __('Primary relational store.'),
                    status: $dbDriver === 'pgsql' ? 'ok' : 'missing',
                ),
                $this->item(
                    name: 'pgvector',
                    version: $database['pgvector'],
                    description: __('Vector similarity search for Knowledge Bases.'),
                    docsUrl: 'https://github.com/pgvector/pgvector',
                ),
                $this->item(
                    name: 'Redis',
                    version: $this->probe->redisVersion(),
                    description: __('Cache, queue, session, and broadcasting backend.'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiGroup(): array
    {
        return [
            'id' => 'ai',
            'label' => __('AI'),
            'items' => [
                $this->item(
                    name: 'laravel/ai',
                    version: $this->probe->composerVersion('laravel/ai'),
                    description: __('Official Laravel AI SDK — chat, streaming, tools, structured output.'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function infraGroup(): array
    {
        return [
            'id' => 'infra',
            'label' => __('Infra'),
            'items' => [
                $this->item(
                    name: 'Laravel Horizon',
                    version: $this->probe->composerVersion('laravel/horizon'),
                    description: __('Queue supervisor and dashboard.'),
                    status: $this->probe->horizonRunning() ? 'ok' : 'outdated',
                ),
                $this->item(
                    name: 'Laravel Reverb',
                    version: $this->probe->composerVersion('laravel/reverb'),
                    description: __('WebSocket server for live broadcasts.'),
                    status: $this->probe->reverbReachable() ? 'ok' : 'outdated',
                ),
                $this->item(
                    name: 'Laravel Wayfinder',
                    version: $this->probe->composerVersion('laravel/wayfinder'),
                    description: __('Type-safe route helpers surfaced to the frontend.'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(
        string $name,
        ?string $version,
        string $description,
        ?string $docsUrl = null,
        ?string $status = null,
    ): array {
        // Default: ok if version present, missing if not.
        if ($status === null) {
            $status = $version === null || $version === 'unknown' ? 'missing' : 'ok';
        }

        return [
            'name' => $name,
            'version' => $version ?? '—',
            'description' => $description,
            'status' => $status,
            'docsUrl' => $docsUrl,
        ];
    }
}
