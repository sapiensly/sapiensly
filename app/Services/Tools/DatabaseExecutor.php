<?php

namespace App\Services\Tools;

use App\Contracts\ToolExecutor;
use App\DTOs\ToolExecutionResult;
use App\Models\Integration;
use App\Models\Tool;
use App\Services\Tools\Concerns\SubstitutesParameters;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseExecutor implements ToolExecutor
{
    use SubstitutesParameters;

    private const MAX_ROWS = 1000;

    private const TIMEOUT_SECONDS = 30;

    /**
     * Dangerous SQL keywords that should be blocked in read-only mode.
     */
    private const DANGEROUS_KEYWORDS = [
        'INSERT',
        'UPDATE',
        'DELETE',
        'DROP',
        'TRUNCATE',
        'ALTER',
        'CREATE',
        'GRANT',
        'REVOKE',
        'EXEC',
        'EXECUTE',
        'INTO',
    ];

    public function __construct(
        private DatabaseConnectionFactory $dbConnections,
        private ToolConnectionResolver $integrations,
    ) {}

    public function execute(Tool $tool, array $parameters, array $config): ToolExecutionResult
    {
        $startTime = microtime(true);

        // A connected tool borrows its DSN from a database Connection; only the
        // query + read-only flag live on the tool.
        $integration = $this->integrations->resolve($tool);
        if ($integration instanceof Integration && $integration->isDatabase()) {
            $config = array_merge($integration->databaseConnectionConfig(), [
                'query_template' => $config['query_template'] ?? '',
                'read_only' => $config['read_only'] ?? true,
            ]);
        }

        try {
            // Open a temporary connection to the external database.
            $connectionName = $this->dbConnections->open($config);

            $query = $config['query_template'] ?? '';
            $readOnly = $config['read_only'] ?? true;

            // Security check for read-only mode
            if ($readOnly && $this->containsDangerousKeywords($query)) {
                return ToolExecutionResult::failure(
                    error: 'Query contains disallowed keywords for read-only mode. Only SELECT queries are permitted.',
                    metadata: ['query' => $query],
                    executionTimeMs: (microtime(true) - $startTime) * 1000,
                );
            }

            // Extract and bind named parameters
            $bindings = $this->extractNamedParameters($query, $parameters);

            // Execute the query with timeout
            DB::connection($connectionName)->statement("SET statement_timeout = '".self::TIMEOUT_SECONDS."s'");

            $results = DB::connection($connectionName)
                ->select($query, $bindings);

            // Limit results
            $totalCount = count($results);
            if ($totalCount > self::MAX_ROWS) {
                $results = array_slice($results, 0, self::MAX_ROWS);
            }

            // Convert to arrays
            $results = array_map(fn ($row) => (array) $row, $results);

            $executionTimeMs = (microtime(true) - $startTime) * 1000;

            // Clean up temporary connection
            $this->dbConnections->close($connectionName);

            return ToolExecutionResult::success(
                data: $results,
                metadata: [
                    'row_count' => count($results),
                    'total_count' => $totalCount,
                    'truncated' => $totalCount > self::MAX_ROWS,
                    'driver' => $config['driver'] ?? 'unknown',
                ],
                executionTimeMs: $executionTimeMs,
            );
        } catch (QueryException $e) {
            Log::warning('Database tool query failed', [
                'tool_id' => $tool->id,
                'error' => $e->getMessage(),
            ]);

            // Remove connection name from error message for security
            $errorMessage = preg_replace('/\[tool_db_[a-f0-9]+\]/', '[database]', $e->getMessage());

            return ToolExecutionResult::failure(
                error: 'Query error: '.$errorMessage,
                metadata: ['sql_state' => $e->getCode()],
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        } catch (\Exception $e) {
            Log::error('Database tool execution error', [
                'tool_id' => $tool->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ToolExecutionResult::failure(
                error: 'Execution error: '.$e->getMessage(),
                executionTimeMs: (microtime(true) - $startTime) * 1000,
            );
        } finally {
            // Ensure connection is removed
            if (isset($connectionName)) {
                $this->dbConnections->close($connectionName);
            }
        }
    }

    public function validate(Tool $tool, array $parameters, array $config): array
    {
        $errors = [];

        // Connected tools borrow the DSN from the database Connection; only the
        // query lives on the tool.
        $connected = ! empty($config['integration_id']);

        if (! $connected) {
            $driver = $config['driver'] ?? '';
            if (empty($driver)) {
                $errors['driver'] = 'Database driver is required';
            }

            if ($driver !== 'sqlite') {
                if (empty($config['host'])) {
                    $errors['host'] = 'Database host is required';
                }
                if (empty($config['username'])) {
                    $errors['username'] = 'Database username is required';
                }
            }

            if (empty($config['database'])) {
                $errors['database'] = 'Database name is required';
            }
        }

        if (empty($config['query_template'])) {
            $errors['query_template'] = 'SQL query template is required';
        }

        // Check for SQL injection patterns in the query template
        $query = $config['query_template'] ?? '';
        if ($this->hasSqlInjectionRisk($query)) {
            $errors['query_template'] = 'Query template appears to have SQL injection risks';
        }

        return $errors;
    }

    /**
     * Check if query contains dangerous keywords (for read-only mode).
     */
    private function containsDangerousKeywords(string $query): bool
    {
        $upperQuery = strtoupper($query);

        foreach (self::DANGEROUS_KEYWORDS as $keyword) {
            // Check for keyword as a whole word
            if (preg_match('/\b'.$keyword.'\b/', $upperQuery)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for potential SQL injection risks in the query template.
     */
    private function hasSqlInjectionRisk(string $query): bool
    {
        // Check for string concatenation patterns that might indicate injection risk
        $riskyPatterns = [
            '/\$\{/', // ${variable}
            '/\+\s*["\']/', // + "string"
            '/["\'\s]\s*\|\|/', // string concatenation operator
            '/CONCAT\s*\(/i', // CONCAT function with potential user input
        ];

        foreach ($riskyPatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return true;
            }
        }

        return false;
    }
}
