<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Admin v2 Stack — "what's this platform made of" dashboard. Pulls versions
 * straight from composer.lock / package.json (no composer-outdated roundtrip
 * on each page load) and supplements with live probes for Horizon, Reverb,
 * Redis, and Postgres. Read-only.
 *
 * Returns the `StackProps` contract defined in `lib/admin/types.ts`: five
 * ordered groups (runtime / frontend / data / ai / infra), each with rows
 * carrying a status dot ('ok' | 'outdated' | 'missing').
 */
class AdminV2StackController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin-v2/Stack', [
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
                    version: $this->composerVersion('inertiajs/inertia-laravel'),
                    description: __('Server-rendered SPA bridge between Laravel and Vue.'),
                    docsUrl: 'https://inertiajs.com/',
                ),
                $this->item(
                    name: 'Fortify',
                    version: $this->composerVersion('laravel/fortify'),
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
                    version: $this->npmVersion('vue'),
                    description: __('UI framework — every admin screen is a Vue SFC.'),
                    docsUrl: 'https://vuejs.org/',
                ),
                $this->item(
                    name: 'TypeScript',
                    version: $this->npmVersion('typescript'),
                    description: __('Static types for the frontend.'),
                    docsUrl: 'https://www.typescriptlang.org/',
                ),
                $this->item(
                    name: 'Tailwind CSS',
                    version: $this->npmVersion('tailwindcss'),
                    description: __('Utility-first styling with the brand token block.'),
                    docsUrl: 'https://tailwindcss.com/',
                ),
                $this->item(
                    name: 'reka-ui',
                    version: $this->npmVersion('reka-ui'),
                    description: __('Accessible primitives under every shadcn-vue component.'),
                    docsUrl: 'https://reka-ui.com/',
                ),
                $this->item(
                    name: 'Vite',
                    version: $this->npmVersion('vite'),
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
        $dbDriver = (string) config('database.default', 'pgsql');
        $dbVersion = $this->safeDbScalar('select version()') ?? 'unknown';

        return [
            'id' => 'data',
            'label' => __('Data'),
            'items' => [
                $this->item(
                    name: 'PostgreSQL',
                    version: $this->shortPgVersion((string) $dbVersion),
                    description: __('Primary relational store.'),
                    status: $dbDriver === 'pgsql' ? 'ok' : 'missing',
                ),
                $this->item(
                    name: 'pgvector',
                    version: $this->safeDbScalar("select extversion from pg_extension where extname = 'vector'") ?? null,
                    description: __('Vector similarity search for Knowledge Bases.'),
                    docsUrl: 'https://github.com/pgvector/pgvector',
                ),
                $this->item(
                    name: 'Redis',
                    version: $this->redisVersion(),
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
                    version: $this->composerVersion('laravel/ai'),
                    description: __('Prism-powered AI abstraction — chat, streaming, tools.'),
                ),
                $this->item(
                    name: 'Prism',
                    version: $this->composerVersion('prism-php/prism'),
                    description: __('LLM driver layer used by laravel/ai.'),
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
                    version: $this->composerVersion('laravel/horizon'),
                    description: __('Queue supervisor and dashboard.'),
                    status: $this->horizonRunning() ? 'ok' : 'outdated',
                ),
                $this->item(
                    name: 'Laravel Reverb',
                    version: $this->composerVersion('laravel/reverb'),
                    description: __('WebSocket server for live broadcasts.'),
                    status: $this->reverbReachable() ? 'ok' : 'outdated',
                ),
                $this->item(
                    name: 'Laravel Wayfinder',
                    version: $this->composerVersion('laravel/wayfinder'),
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

    private function composerVersion(string $package): ?string
    {
        static $cache = null;
        if ($cache === null) {
            $lock = @file_get_contents(base_path('composer.lock'));
            if ($lock === false) {
                $cache = [];

                return null;
            }
            $data = json_decode($lock, true) ?: [];
            $cache = [];
            foreach (array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []) as $pkg) {
                if (isset($pkg['name'], $pkg['version'])) {
                    $cache[$pkg['name']] = ltrim((string) $pkg['version'], 'v');
                }
            }
        }

        return $cache[$package] ?? null;
    }

    private function npmVersion(string $package): ?string
    {
        static $cache = null;
        if ($cache === null) {
            $pkg = @file_get_contents(base_path('package.json'));
            if ($pkg === false) {
                $cache = [];

                return null;
            }
            $data = json_decode($pkg, true) ?: [];
            $cache = array_merge(
                (array) ($data['dependencies'] ?? []),
                (array) ($data['devDependencies'] ?? []),
            );
        }

        $value = $cache[$package] ?? null;

        return $value ? ltrim((string) $value, '^~') : null;
    }

    private function redisVersion(): ?string
    {
        try {
            $info = Redis::connection('default')->info();
            $version = $info['Server']['redis_version'] ?? ($info['redis_version'] ?? null);

            return $version ? (string) $version : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function horizonRunning(): bool
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();

            return ! empty($masters);
        } catch (Throwable) {
            return false;
        }
    }

    private function reverbReachable(): bool
    {
        $host = (string) config('reverb.servers.reverb.host', '127.0.0.1');
        $port = (int) config('reverb.servers.reverb.port', 8080);

        try {
            $fp = @fsockopen($host, $port, $errno, $errstr, 0.5);
            if (! $fp) {
                return false;
            }
            fclose($fp);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function safeDbScalar(string $sql): ?string
    {
        try {
            $value = DB::scalar($sql);

            return $value === null ? null : (string) $value;
        } catch (Throwable) {
            return null;
        }
    }

    private function shortPgVersion(string $raw): string
    {
        if (preg_match('/\bPostgreSQL\s+([\d.]+)/i', $raw, $m)) {
            return $m[1];
        }

        return $raw === '' ? 'unknown' : $raw;
    }
}
