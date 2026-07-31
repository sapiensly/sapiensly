<?php

namespace App\Mcp\Tools\Platform;

use App\Mcp\Tools\SysadminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Throwable;

#[Description('Read the tail of the application log. Filter by level (error, warning, info, …) and by a text pattern, and choose how many entries to return. Entries are parsed per log record, so a stack trace comes back attached to its own message instead of as loose lines. Use it to see what a failure actually said, after platform_health or list_failed_jobs told you something broke. Read-only. Log lines can contain request data — treat the output as sensitive and do not paste it anywhere public.')]
class ReadPlatformLogsTool extends SysadminTool
{
    /** Bytes read from the end of the file. Enough for a deep tail, bounded. */
    private const TAIL_BYTES = 512 * 1024;

    private const MAX_ENTRY_LENGTH = 4000;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'level' => ['sometimes', 'nullable', 'string', 'max:20'],
            'pattern' => ['sometimes', 'nullable', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'file' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $limit = (int) ($validated['limit'] ?? 20);

        $file = $this->resolveFile($validated['file'] ?? null);
        if ($file === null) {
            return Response::error('No log file found. Available: '.implode(', ', $this->availableFiles()));
        }

        try {
            $raw = $this->tail($file);
        } catch (Throwable $e) {
            return Response::error('Could not read the log file: '.$e->getMessage());
        }

        $entries = $this->parse($raw);

        if (! empty($validated['level'])) {
            $level = strtolower($validated['level']);
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry) => str_contains(strtolower((string) $entry['level']), $level),
            ));
        }

        if (! empty($validated['pattern'])) {
            $needle = strtolower($validated['pattern']);
            $entries = array_values(array_filter(
                $entries,
                static fn (array $entry) => str_contains(strtolower($entry['message']), $needle),
            ));
        }

        // Newest first, then trim — the tail is what anyone debugging wants.
        $entries = array_slice(array_reverse($entries), 0, $limit);

        return Response::json([
            'file' => basename($file),
            'available_files' => $this->availableFiles(),
            'returned' => count($entries),
            'entries' => $entries,
        ]);
    }

    /**
     * @return list<string>
     */
    private function availableFiles(): array
    {
        $files = glob(storage_path('logs/*.log')) ?: [];

        // Newest first so the default pick is the live one.
        usort($files, static fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return array_map('basename', array_slice($files, 0, 20));
    }

    private function resolveFile(?string $requested): ?string
    {
        $directory = storage_path('logs');

        if ($requested !== null && $requested !== '') {
            // basename() is the boundary: a caller may choose among the log
            // files, never walk out of the log directory.
            $path = $directory.DIRECTORY_SEPARATOR.basename($requested);

            return is_file($path) ? $path : null;
        }

        $files = glob($directory.'/*.log') ?: [];
        if ($files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    private function tail(string $file): string
    {
        $size = filesize($file) ?: 0;
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            if ($size > self::TAIL_BYTES) {
                fseek($handle, -self::TAIL_BYTES, SEEK_END);
                // The first line after a mid-file seek is usually a fragment.
                fgets($handle);
            }

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Split the raw tail into records. A new entry starts at a bracketed
     * timestamp; everything until the next one (stack traces, context) belongs
     * to the entry that opened it.
     *
     * @return list<array{timestamp: ?string, level: ?string, message: string}>
     */
    private function parse(string $raw): array
    {
        $entries = [];
        $current = null;

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^\[(?<time>[^\]]+)\]\s+(?<channel>\S+?)\.(?<level>[A-Z]+):\s*(?<message>.*)$/', $line, $matches) === 1) {
                if ($current !== null) {
                    $entries[] = $this->finish($current);
                }

                $current = [
                    'timestamp' => $matches['time'],
                    'level' => strtolower($matches['level']),
                    'message' => $matches['message'],
                ];

                continue;
            }

            if ($current !== null && trim($line) !== '') {
                $current['message'] .= "\n".$line;
            }
        }

        if ($current !== null) {
            $entries[] = $this->finish($current);
        }

        return $entries;
    }

    /**
     * @param  array{timestamp: ?string, level: ?string, message: string}  $entry
     * @return array{timestamp: ?string, level: ?string, message: string}
     */
    private function finish(array $entry): array
    {
        if (strlen($entry['message']) > self::MAX_ENTRY_LENGTH) {
            $entry['message'] = substr($entry['message'], 0, self::MAX_ENTRY_LENGTH)."\n… (truncated)";
        }

        return $entry;
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'level' => $schema->string()->description('Only entries at this level: error, warning, info, debug, critical.'),
            'pattern' => $schema->string()->description('Only entries whose text contains this (case-insensitive).'),
            'limit' => $schema->integer()->description('How many entries, newest first. 1-100, default 20.'),
            'file' => $schema->string()->description('Log file name inside storage/logs. Defaults to the most recently written one.'),
        ];
    }
}
