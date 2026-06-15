<?php

use App\Events\Builder\BuilderActivity;

/**
 * Builder UI feedback — the live activity signal carries the model + phase (and
 * tool, when calling one) on the conversation's private channel, so the UI can
 * always show what the model is doing during a turn.
 */
it('broadcasts the model and phase on the conversation channel', function () {
    $event = new BuilderActivity('cnv_abc', 'bmsg_xyz', 'tool', 'claude-haiku-4-5-20251001', 'read_manifest');

    expect($event->broadcastAs())->toBe('BuilderActivity')
        ->and($event->broadcastOn()[0]->name)->toBe('private-builder.conversation.cnv_abc')
        ->and($event->broadcastWith())->toBe([
            'message_id' => 'bmsg_xyz',
            'phase' => 'tool',
            'model' => 'claude-haiku-4-5-20251001',
            'tool' => 'read_manifest',
        ]);
});
