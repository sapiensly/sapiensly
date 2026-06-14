<?php

namespace App\Services\Chat\Actions;

use App\Models\Chat;

/**
 * The v1 fallback action: a described, parametrized recommendation that is NOT
 * wired to a real workflow. "Executing" it simply acknowledges the close — the
 * user carries out the steps manually. Real handlers replace this per action_type.
 */
class ManualAction implements ActionHandler
{
    public const KEY = 'manual';

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{summary: string, data?: array<string, mixed>}
     */
    public function execute(Chat $chat, array $payload): array
    {
        $label = (string) ($payload['action_label'] ?? 'the proposed action');

        return [
            'summary' => 'Marked as actioned: '.$label.'. This is a manual close — carry out the steps above to complete it.',
            'data' => [
                'parameters' => (array) ($payload['parameters'] ?? []),
            ],
        ];
    }
}
