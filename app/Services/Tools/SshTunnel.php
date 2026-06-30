<?php

namespace App\Services\Tools;

use RuntimeException;

/**
 * Opens an SSH local port-forward (`ssh -L`) to reach a database that's only
 * routable through a bastion. phpseclib3 has no port-forwarding, so this shells
 * out to the `ssh` binary as a managed background process and tears it down
 * cleanly.
 *
 * Auth methods:
 *   - key, no passphrase  → BatchMode (no prompts).
 *   - key + passphrase    → fed non-interactively via SSH_ASKPASS.
 *   - password            → fed non-interactively via SSH_ASKPASS.
 *
 * Secrets are never placed on the command line; they go through a 0600 file a
 * 0700 askpass helper reads, and all temp files are removed on close.
 *
 * Expected `ssh` config shape:
 *   host, port?, username, private_key?, passphrase?, password?,
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

        $binary = $this->binary();
        $this->ensureAvailable($binary);

        $localPort = $this->freePort();
        $tempFiles = [];

        $usesKey = ! empty($ssh['private_key']);
        $keyFile = null;
        if ($usesKey) {
            $keyFile = $this->writeTemp(rtrim((string) $ssh['private_key'])."\n", 0600);
            $tempFiles[] = $keyFile;
        }

        // The secret ssh may need to prompt for: a key's passphrase, or the
        // login password when there's no key.
        $secret = $usesKey ? (string) ($ssh['passphrase'] ?? '') : (string) ($ssh['password'] ?? '');
        $interactive = $secret !== '';

        $env = null;
        if ($interactive) {
            $secretFile = $this->writeTemp($secret, 0600);
            $askpass = $this->writeTemp("#!/bin/sh\ncat ".escapeshellarg($secretFile)."\n", 0700);
            $tempFiles[] = $secretFile;
            $tempFiles[] = $askpass;
            $env = $this->envWithAskpass($askpass);
        }

        $command = $this->buildCommand($ssh, $localPort, $targetHost, $targetPort, $keyFile, $interactive);
        $command[0] = $binary;

        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );

        if (! is_resource($process)) {
            $this->cleanup($tempFiles);
            throw new RuntimeException('Failed to start the SSH tunnel process.');
        }

        stream_set_blocking($pipes[2], false);

        if (! $this->waitForPort($localPort, (float) ($ssh['startup_timeout'] ?? 15))) {
            $error = trim((string) stream_get_contents($pipes[2]));
            @proc_terminate($process);
            @proc_close($process);
            $this->cleanup($tempFiles);

            throw new RuntimeException('SSH tunnel did not come up'.($error !== '' ? ': '.$error : '.'));
        }

        return new SshTunnelHandle($localPort, $process, $tempFiles);
    }

    /**
     * The `ssh` argv. Kept public + pure so the security-relevant flags are
     * testable without spawning a process. `$interactive` means a password /
     * passphrase will be fed via SSH_ASKPASS, so BatchMode must stay off.
     *
     * @param  array<string, mixed>  $ssh
     * @return array<int, string>
     */
    public function buildCommand(array $ssh, int $localPort, string $targetHost, int $targetPort, ?string $keyFile, bool $interactive = false): array
    {
        $command = [
            'ssh', '-N',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'ConnectTimeout='.(int) ($ssh['connect_timeout'] ?? 10),
            '-o', 'ServerAliveInterval=15',
            // accept-new is trust-on-first-use: safer than `no`, and a pinned
            // known_hosts_file (below) upgrades it to strict verification.
            '-o', 'StrictHostKeyChecking='.($ssh['strict_host_key'] ?? 'accept-new'),
        ];

        // No prompts at all when there's no secret to feed.
        if (! $interactive) {
            $command[] = '-o';
            $command[] = 'BatchMode=yes';
        }

        if (! empty($ssh['known_hosts_file'])) {
            $command[] = '-o';
            $command[] = 'UserKnownHostsFile='.$ssh['known_hosts_file'];
        }

        if ($keyFile !== null) {
            $command[] = '-i';
            $command[] = $keyFile;
            $command[] = '-o';
            $command[] = 'IdentitiesOnly=yes';
        } else {
            // Password auth — don't let ssh try local keys first.
            $command[] = '-o';
            $command[] = 'PubkeyAuthentication=no';
            $command[] = '-o';
            $command[] = 'PreferredAuthentications=password,keyboard-interactive';
        }

        $command[] = '-p';
        $command[] = (string) ((int) ($ssh['port'] ?? 22));
        $command[] = '-L';
        $command[] = "127.0.0.1:{$localPort}:{$targetHost}:{$targetPort}";
        $command[] = $ssh['username'].'@'.$ssh['host'];

        return $command;
    }

    /**
     * Whether the ssh client is reachable. Used by the preflight check and the
     * `about` diagnostic, without spawning a process.
     */
    public static function available(?string $binary = null): bool
    {
        $binary ??= (string) config('integrations.ssh_binary', 'ssh');

        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_executable($binary);
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable(rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary)) {
                return true;
            }
        }

        return false;
    }

    private function binary(): string
    {
        return (string) config('integrations.ssh_binary', 'ssh');
    }

    private function ensureAvailable(string $binary): void
    {
        if (! self::available($binary)) {
            throw new RuntimeException(
                'SSH tunneling requires the `ssh` client, which is not installed on this server. '
                .'Install openssh-client, or set INTEGRATIONS_SSH_BINARY to its full path.',
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function envWithAskpass(string $askpass): array
    {
        $env = getenv();
        $env['SSH_ASKPASS'] = $askpass;
        // OpenSSH 8.4+: use the askpass program even with a tty present.
        $env['SSH_ASKPASS_REQUIRE'] = 'force';
        // Older ssh only consults SSH_ASKPASS when DISPLAY is set.
        $env['DISPLAY'] = $env['DISPLAY'] ?? ':0';

        return $env;
    }

    private function writeTemp(string $contents, int $mode): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ssh_');
        file_put_contents($path, $contents);
        chmod($path, $mode);

        return $path;
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

    /**
     * @param  array<int, string>  $files
     */
    private function cleanup(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
