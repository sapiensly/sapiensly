<?php

namespace App\Services\Tools;

/**
 * A live SSH local-forward: a port on 127.0.0.1 that relays to the target
 * database through a bastion. Owns the `ssh` process and any temp files (key,
 * secret, askpass helper) so close() leaves nothing behind.
 */
class SshTunnelHandle
{
    /**
     * @param  resource|null  $process
     * @param  array<int, string>  $tempFiles
     */
    public function __construct(
        public readonly int $localPort,
        private mixed $process = null,
        private array $tempFiles = [],
    ) {}

    public function close(): void
    {
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
            $this->process = null;
        }

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }
}
