<?php

namespace App\Events\Apps;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something in this app changed. Not WHAT changed.
 *
 * The payload is deliberately three ids and a verb, and that restraint is the
 * whole security design. A broadcast carrying the row would reach every
 * subscriber on the channel — including a role whose row_filter hides exactly
 * that row, and a user whose object policy denies the object outright. There is
 * no per-recipient filtering in a broadcast, so the only safe thing to send is
 * the FACT that something moved.
 *
 * Each listener then re-reads through the ordinary access-filtered path, which
 * is where the row_filter, the environment scope and the field hiding all live.
 * Somebody who may not see the record learns only that the app is busy, which
 * they could tell by watching anybody's screen anyway.
 *
 * The environment travels because a demo write must not make a production table
 * blink: the two are different data and a refresh that crossed them would show
 * a reader nothing new and cost them a query.
 */
class RecordChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $appId,
        public string $objectId,
        public string $recordId,
        /** created | updated | deleted */
        public string $verb,
        public string $environment,
        /**
         * Who did it, so their own browser can ignore the echo of its own
         * write — it already applied the change optimistically, and a reload
         * would fight the cursor of somebody mid-edit.
         */
        public ?int $actorId = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'RecordChanged';
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("app.records.{$this->appId}")];
    }
}
