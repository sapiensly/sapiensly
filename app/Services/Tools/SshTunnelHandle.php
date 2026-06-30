<?php

namespace App\Services\Tools;

/**
 * A live SSH local-forward: a port on 127.0.0.1 that relays to the target
 * database through a bastion. Owns the `ssh` process and the temp key file so
 * close() leaves nothing behind.
 */
class SshTunnelHandle
{
    /**
     * @param  resource|null  $process
     */
    public function __construct(
        public readonly int $localPort,
        private mixed $process = null,
        private ?string $keyFile = null,
    ) {}

    public function close(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
            $this->process = null;
        }

        if ($this->keyFile !== null && is_file($this->keyFile)) {
            @unlink($this->keyFile);
            $this->keyFile = null;
        }
    }
}
