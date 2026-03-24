<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

class StackController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('system/Stack', [
            'stack' => [
                'php' => PHP_VERSION,
                'laravel' => Application::VERSION,
                'laravel_ai' => $this->getComposerPackageVersion('laravel/ai'),
                'node' => trim(shell_exec('node -v') ?? ''),
                'vue' => $this->getPackageVersion('vue'),
                'database' => $this->getDatabaseVersion(),
            ],
            'services' => [
                'horizon' => $this->getHorizonStatus(),
                'reverb' => $this->getReverbStatus(),
                'redis' => $this->getRedisStatus(),
            ],
        ]);
    }

    private function getDatabaseVersion(): array
    {
        $version = DB::scalar('SELECT version()');
        $driver = config('database.default');

        return [
            'driver' => $driver,
            'version' => $version,
        ];
    }

    private function getComposerPackageVersion(string $package): string
    {
        $lockFile = base_path('composer.lock');
        $content = file_get_contents($lockFile);

        if ($content === false) {
            return 'unknown';
        }

        $data = json_decode($content, true);
        $packages = array_merge($data['packages'] ?? [], $data['packages-dev'] ?? []);

        foreach ($packages as $pkg) {
            if (($pkg['name'] ?? '') === $package) {
                return ltrim($pkg['version'] ?? 'unknown', 'v');
            }
        }

        return 'unknown';
    }

    private function getHorizonStatus(): array
    {
        $version = $this->getComposerPackageVersion('laravel/horizon');

        try {
            $masters = app(MasterSupervisorRepository::class)->all();
            $isRunning = ! empty($masters);

            return ['status' => $isRunning ? 'running' : 'stopped', 'version' => $version];
        } catch (\Exception) {
            return ['status' => 'error', 'version' => $version];
        }
    }

    private function getReverbStatus(): array
    {
        $version = $this->getComposerPackageVersion('laravel/reverb');
        $host = config('reverb.servers.reverb.host', '127.0.0.1');
        $port = config('reverb.servers.reverb.port', 8080);

        try {
            $connection = @fsockopen($host, (int) $port, $errno, $errstr, 1);
            if ($connection) {
                fclose($connection);

                return ['status' => 'running', 'version' => $version];
            }

            return ['status' => 'stopped', 'version' => $version];
        } catch (\Exception) {
            return ['status' => 'stopped', 'version' => $version];
        }
    }

    private function getRedisStatus(): array
    {
        try {
            $info = Redis::connection('default')->info();
            $version = $info['Server']['redis_version'] ?? ($info['redis_version'] ?? 'unknown');

            return ['status' => 'running', 'version' => $version];
        } catch (\Exception) {
            return ['status' => 'stopped', 'version' => 'unknown'];
        }
    }

    private function getPackageVersion(string $package): string
    {
        $composerLock = base_path('package.json');
        $content = file_get_contents($composerLock);

        if ($content === false) {
            return 'unknown';
        }

        $data = json_decode($content, true);
        $dependencies = array_merge(
            $data['dependencies'] ?? [],
            $data['devDependencies'] ?? [],
        );

        return $dependencies[$package] ?? 'unknown';
    }
}
