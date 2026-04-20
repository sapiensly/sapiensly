<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Returns synthetic data matching the `DashboardProps` TypeScript contract in
 * `resources/js/lib/admin/types.ts`. Real queries (ticket metrics, token
 * ledger, provider spend, audit log) land in a follow-up commit per HANDOFF
 * §1.3.
 */
class AdminV2DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin-v2/Dashboard', [
            'stats' => $this->seedStats(),
            'layers' => $this->seedLayers(),
            'spend' => $this->seedSpend(),
            'health' => $this->seedHealth(),
            'audit' => $this->seedAudit(),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function seedStats(): array
    {
        return [
            'ticketsResolved' => [
                'value' => 2914,
                'display' => '2,914',
                'caption' => __('Tickets resolved (24h)'),
                'delta' => 18.4,
                'deltaDir' => 'up',
                'series' => [1980, 2040, 2120, 2250, 2320, 2410, 2530, 2640, 2760, 2840, 2914],
            ],
            'avgHandleTime' => [
                'value' => 11.4,
                'display' => '11.4s',
                'caption' => __('p95 2.1s'),
                'delta' => -23,
                'deltaDir' => 'up',
                'series' => [14.8, 14.2, 13.7, 13.2, 12.9, 12.5, 12.0, 11.8, 11.6, 11.5, 11.4],
            ],
            'tokensUsed' => [
                'value' => 18_300_000,
                'display' => '18.3M',
                'caption' => __('Tokens used today'),
                'delta' => 12.6,
                'deltaDir' => 'up',
                'series' => [12.0, 12.8, 13.5, 14.1, 14.9, 15.6, 16.2, 17.0, 17.6, 18.0, 18.3],
            ],
            'spendToday' => [
                'value' => 24.81,
                'display' => '$24.81',
                'caption' => __('$712 MTD'),
                'delta' => 9.1,
                'deltaDir' => 'up',
                'series' => [16.2, 17.4, 18.5, 19.3, 20.1, 21.2, 22.0, 22.8, 23.6, 24.3, 24.81],
            ],
            'totalUsers' => [
                'value' => 847,
                'display' => '847',
                'caption' => __('124 organizations'),
            ],
        ];
    }

    /**
     * @return array<string, array{count: int, subtitle: string, series: array<int, int>}>
     */
    private function seedLayers(): array
    {
        return [
            'understand' => [
                'count' => 2914,
                'subtitle' => __('Triage agent — decode intent, urgency, sentiment'),
                'series' => [1850, 1960, 2080, 2190, 2320, 2450, 2580, 2700, 2810, 2870, 2914],
            ],
            'discover' => [
                'count' => 2681,
                'subtitle' => __('Knowledge agent — query docs with surgical precision'),
                'series' => [1720, 1810, 1900, 2010, 2120, 2250, 2370, 2490, 2570, 2630, 2681],
            ],
            'resolve' => [
                'count' => 1973,
                'subtitle' => __('Action agent — execute real-world operations'),
                'series' => [1220, 1310, 1410, 1500, 1590, 1680, 1760, 1840, 1900, 1950, 1973],
            ],
        ];
    }

    /**
     * @return array{providers: array<int, array{name: string, calls: int, cost: float, color: string}>}
     */
    private function seedSpend(): array
    {
        return [
            'providers' => [
                ['name' => 'Anthropic', 'calls' => 184_203, 'cost' => 14.82, 'color' => 'var(--sp-spectrum-magenta)'],
                ['name' => 'OpenAI', 'calls' => 98_441, 'cost' => 6.91, 'color' => 'var(--sp-spectrum-cyan)'],
                ['name' => 'Mistral', 'calls' => 38_920, 'cost' => 1.74, 'color' => 'var(--sp-spectrum-indigo)'],
                ['name' => 'Azure OpenAI', 'calls' => 24_817, 'cost' => 1.34, 'color' => 'var(--sp-accent-blue)'],
            ],
        ];
    }

    /**
     * @return array<int, array{id: string, label: string, detail: string, status: string, lastCheckAt: string}>
     */
    private function seedHealth(): array
    {
        $now = now();

        return [
            [
                'id' => 'llm',
                'label' => __('Default LLM'),
                'detail' => 'Anthropic · claude-haiku-4.5',
                'status' => 'ok',
                'lastCheckAt' => $now->copy()->subSeconds(2)->toIso8601String(),
            ],
            [
                'id' => 'embeddings',
                'label' => __('Embeddings'),
                'detail' => 'OpenAI · text-embedding-3-small',
                'status' => 'ok',
                'lastCheckAt' => $now->copy()->subSeconds(8)->toIso8601String(),
            ],
            [
                'id' => 'db',
                'label' => __('Postgres'),
                'detail' => 'db.sapiensly.com:5432 · pgvector 0.7.4',
                'status' => 'ok',
                'lastCheckAt' => $now->copy()->subSeconds(4)->toIso8601String(),
            ],
            [
                'id' => 'storage',
                'label' => __('Object storage'),
                'detail' => 'S3 eu-west-1 · 87% capacity',
                'status' => 'warn',
                'lastCheckAt' => $now->copy()->subSeconds(22)->toIso8601String(),
            ],
            [
                'id' => 'queue',
                'label' => __('Horizon queue'),
                'detail' => __('4 workers · 12 pending jobs'),
                'status' => 'ok',
                'lastCheckAt' => $now->copy()->subSeconds(1)->toIso8601String(),
            ],
            [
                'id' => 'reverb',
                'label' => __('Reverb (websockets)'),
                'detail' => __('842 active connections'),
                'status' => 'ok',
                'lastCheckAt' => $now->copy()->subSeconds(1)->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function seedAudit(): array
    {
        $now = now();

        return [
            [
                'id' => '01jf0000000000000000000001',
                'icon' => 'sliders',
                'actor' => ['id' => 1, 'name' => 'Álvaro Ruiz'],
                'action' => __('updated'),
                'target' => __('Global AI · default LLM'),
                'targetHref' => '/admin2/ai',
                'context' => 'claude-sonnet-4.5 → claude-haiku-4.5',
                'at' => $now->copy()->subMinutes(2)->toIso8601String(),
            ],
            [
                'id' => '01jf0000000000000000000002',
                'icon' => 'library',
                'actor' => ['id' => null, 'name' => __('System')],
                'action' => __('installed'),
                'target' => 'pgvector 0.7.4',
                'targetHref' => '/admin2/cloud',
                'context' => __('database db.sapiensly.com'),
                'at' => $now->copy()->subMinutes(14)->toIso8601String(),
            ],
            [
                'id' => '01jf0000000000000000000003',
                'icon' => 'user',
                'actor' => ['id' => 1, 'name' => 'Álvaro Ruiz'],
                'action' => __('blocked'),
                'target' => 'Jonas Weber',
                'targetHref' => '/admin2/users',
                'context' => __('onfleet.de · payment fraud flag'),
                'at' => $now->copy()->subHour()->toIso8601String(),
            ],
            [
                'id' => '01jf0000000000000000000004',
                'icon' => 'eye',
                'actor' => ['id' => 3, 'name' => 'Marta Vilanova'],
                'action' => __('impersonated'),
                'target' => 'Priya Sharma',
                'targetHref' => '/admin2/users',
                'context' => __('session · 12m'),
                'at' => $now->copy()->subHours(3)->toIso8601String(),
            ],
            [
                'id' => '01jf0000000000000000000005',
                'icon' => 'refresh',
                'actor' => ['id' => 1, 'name' => 'Álvaro Ruiz'],
                'action' => __('rotated'),
                'target' => __('OpenAI API key'),
                'targetHref' => '/admin2/ai',
                'context' => 'sk-***-712z',
                'at' => $now->copy()->subHours(5)->toIso8601String(),
            ],
            [
                'id' => '01jf0000000000000000000006',
                'icon' => 'library',
                'actor' => ['id' => null, 'name' => __('System')],
                'action' => __('auto-scaled'),
                'target' => __('Horizon workers 2 → 4'),
                'targetHref' => null,
                'context' => __('queue depth threshold'),
                'at' => $now->copy()->subDay()->toIso8601String(),
            ],
        ];
    }
}
