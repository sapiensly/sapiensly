<?php

namespace App\Services\Tools;

use RuntimeException;

/**
 * Opens an SSH local port-forward (`ssh -L`) to reach a database that's only
 * routable through a bastion. phpseclib3 has no port-forwarding, so this shells
 * out to the `ssh` binary as a managed background process and tears it down
 * cleanly.
 *
 * Key-based auth only for now (the common bastion case): an encrypted key or
 * password prompt can't work under BatchMode without ssh-agent / sshpass.
 *
 * Expected `ssh` config shape:
 *   host, port?, username, private_key,
 *   strict_host_key? ('accept-new'|'yes'|'no'), known_hosts_file?,
 *   connect_timeout?, startup_timeout?
 */
class SshTunnel
{
    public function open(array $ssh, string $targetHost, int $targetPort): SshTunnelHandle
    {
        if (empty($ssh['host']) || empty($ssh['username'])) {
            throw new RuntimeException('SSH tunnel requires a host and username.');
        }

        $localPort = $this->freePort();

        $keyFile = null;
        if (! empty($ssh['private_key'])) {
            $keyFile = tempnam(sys_get_temp_dir(), 'sshkey_');
            file_put_contents($keyFile, rtrim((string) $ssh['private_key'])."\n");
            chmod($keyFile, 0600);
        }

        $command = $this->buildCommand($ssh, $localPort, $targetHost, $targetPort, $keyFile);

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            $this->cleanupKey($keyFile);
            throw new RuntimeException('Failed to start the SSH tunnel process.');
        }

        stream_set_blocking($pipes[2], false);

        if (! $this->waitForPort($localPort, (float) ($ssh['startup_timeout'] ?? 15))) {
            $error = trim((string) stream_get_contents($pipes[2]));
            @proc_terminate($process);
            @proc_close($process);
            $this->cleanupKey($keyFile);

            throw new RuntimeException('SSH tunnel did not come up'.($error !== '' ? ': '.$error : '.'));
        }

        return new SshTunnelHandle($localPort, $process, $keyFile);
    }

    /**
     * The `ssh` argv. Kept public + pure so the security-relevant flags are
     * testable without spawning a process.
     *
     * @param  array<string, mixed>  $ssh
     * @return array<int, string>
     */
    public function buildCommand(array $ssh, int $localPort, string $targetHost, int $targetPort, ?string $keyFile): array
    {
        $command = [
            'ssh', '-N',
            '-o', 'BatchMode=yes',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'ConnectTimeout='.(int) ($ssh['connect_timeout'] ?? 10),
            '-o', 'ServerAliveInterval=15',
            // accept-new is trust-on-first-use: safer than `no`, and a pinned
            // known_hosts_file (below) upgrades it to strict verification.
            '-o', 'StrictHostKeyChecking='.($ssh['strict_host_key'] ?? 'accept-new'),
        ];

        if (! empty($ssh['known_hosts_file'])) {
            $command[] = '-o';
            $command[] = 'UserKnownHostsFile='.$ssh['known_hosts_file'];
        }

        if ($keyFile !== null) {
            $command[] = '-i';
            $command[] = $keyFile;
            $command[] = '-o';
            $command[] = 'IdentitiesOnly=yes';
        }

        $command[] = '-p';
        $command[] = (string) ((int) ($ssh['port'] ?? 22));
        $command[] = '-L';
        $command[] = "127.0.0.1:{$localPort}:{$targetHost}:{$targetPort}";
        $command[] = $ssh['username'].'@'.$ssh['host'];

        return $command;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitForPort(int $port, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
            if ($conn) {
                fclose($conn);

                return true;
            }
            usleep(100_000);
        }

        return false;
    }

    private function cleanupKey(?string $keyFile): void
    {
        if ($keyFile !== null && is_file($keyFile)) {
            @unlink($keyFile);
        }
    }
}
