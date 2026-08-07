<?php

namespace App\Console\Commands;

use App\Support\Push\WebPush;
use Illuminate\Console\Command;

class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid';

    protected $description = 'Generate the VAPID key pair web push notifications are signed with';

    public function handle(): int
    {
        if (config('push.vapid.public') !== null && config('push.vapid.private') !== null) {
            // Every existing subscription is bound to the CURRENT public key.
            // Replacing it does not break sending, it silently stops it: the
            // push services keep accepting requests signed with the new key and
            // the browsers that subscribed under the old one never hear again.
            $this->warn('A VAPID pair is already configured.');
            $this->line('Replacing it invalidates every existing subscription — every device would have to allow notifications again.');

            if (! $this->confirm('Generate a new pair anyway?', false)) {
                return self::SUCCESS;
            }
        }

        $keys = WebPush::generateKeyPair();

        $this->line('');
        $this->line('Add these to your environment:');
        $this->line('');
        $this->line('VAPID_PUBLIC_KEY='.WebPush::encode($keys['public']));
        $this->line('VAPID_PRIVATE_KEY='.WebPush::encode($keys['private']));
        $this->line('');
        $this->comment('The private key signs; nothing but this server should ever hold it.');

        return self::SUCCESS;
    }
}
