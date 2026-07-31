<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use App\Services\Platform\PlatformProbe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Application;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('What this platform is made of: PHP/Laravel/Inertia/Fortify versions, the frontend toolchain (Vue, TypeScript, Tailwind, Vite, reka-ui), the data layer (PostgreSQL, pgvector, Redis), the AI SDK, and the infra services (Horizon, Reverb, Wayfinder) with a live up/down status where one can be probed. Versions are read from composer.lock / package.json — no network call, no composer roundtrip. Read-only.')]
class PlatformStackTool extends SysadminTool
{
    public function handle(Request $request): Response
    {
        $probe = app(PlatformProbe::class);
        $database = $probe->database();

        return Response::json([
            'environment' => app()->environment(),
            'runtime' => [
                'php' => PHP_VERSION,
                'laravel' => Application::VERSION,
                'inertia' => $probe->composerVersion('inertiajs/inertia-laravel'),
                'fortify' => $probe->composerVersion('laravel/fortify'),
                'passport' => $probe->composerVersion('laravel/passport'),
                'mcp' => $probe->composerVersion('laravel/mcp'),
            ],
            'frontend' => [
                'vue' => $probe->npmVersion('vue'),
                'typescript' => $probe->npmVersion('typescript'),
                'tailwindcss' => $probe->npmVersion('tailwindcss'),
                'reka-ui' => $probe->npmVersion('reka-ui'),
                'vite' => $probe->npmVersion('vite'),
            ],
            'data' => [
                'postgresql' => $database['version'],
                'pgvector' => $database['pgvector'],
                'redis' => $probe->redisVersion(),
            ],
            'ai' => [
                'laravel/ai' => $probe->composerVersion('laravel/ai'),
            ],
            'infra' => [
                'horizon' => [
                    'version' => $probe->composerVersion('laravel/horizon'),
                    'running' => $probe->horizonRunning(),
                ],
                'reverb' => [
                    'version' => $probe->composerVersion('laravel/reverb'),
                    'reachable' => $probe->reverbReachable(),
                ],
                'wayfinder' => ['version' => $probe->composerVersion('laravel/wayfinder')],
            ],
        ]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
