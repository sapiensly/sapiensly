<?php

namespace App\Events\Apps;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live progress for a spreadsheet import, broadcast on `app.import.{id}` as the
 * job writes.
 *
 * Informational only. The `app_imports` row is the source of truth — this makes
 * it live, and a dropped socket costs the animation, never the answer.
 */
class AppImportProgress implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $progress  the shape AppImport::toProgress() returns
     */
    public function __construct(
        public string $importId,
        public array $progress,
    ) {}

    public function broadcastAs(): string
    {
        return 'AppImportProgress';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("app.import.{$this->importId}")];
    }
}
