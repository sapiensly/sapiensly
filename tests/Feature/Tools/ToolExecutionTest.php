<?php

use App\DTOs\ToolExecutionResult;
use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Models\Tool;
use App\Models\User;
use App\Services\ToolConfigService;
use App\Services\ToolExecutionService;
use App\Services\Tools\DatabaseExecutor;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->configService = app(ToolConfigService::class);
    $this->executionService = app(ToolExecutionService::class);
});

describe('ToolExecutionResult', function () {
    it('creates a success result', function () {
        $result = ToolExecutionResult::success(
            data: ['key' => 'value'],
            metadata: ['count' => 1],
            executionTimeMs: 100.5,
        );

        expect($result->success)->toBeTrue();
        expect($result->data)->toBe(['key' => 'value']);
        expect($result->error)->toBeNull();
        expect($result->metadata)->toBe(['count' => 1]);
        expect($result->executionTimeMs)->toBe(100.5);
    });

    it('creates a failure result', function () {
        $result = ToolExecutionResult::failure(
            error: 'Something went wrong',
            statusCode: 500,
        );

        expect($result->success)->toBeFalse();
        expect($result->error)->toBe('Something went wrong');
        expect($result->statusCode)->toBe(500);
        expect($result->data)->toBeNull();
    });

    it('serializes to JSON correctly', function () {
        $result = ToolExecutionResult::success(['test' => true]);
        $json = $result->jsonSerialize();

        expect($json)->toHaveKey('success');
        expect($json)->toHaveKey('data');
        expect($json)->not->toHaveKey('error');
    });
});

describe('ToolExecutionService', function () {
    it('returns error for inactive tools', function () {
        $tool = Tool::factory()->restApi()->create([
            'user_id' => $this->user->id,
            'status' => AgentStatus::Draft,
        ]);

        $result = $this->executionService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not active');
    });

    it('returns error for unsupported tool types', function () {
        $tool = Tool::factory()->function()->active()->create([
            'user_id' => $this->user->id,
        ]);

        $result = $this->executionService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('No executor available');
    });

    it('validates tools before execution', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [], // Missing required config
        ]);

        $result = $this->executionService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('Validation failed');
    });

    it('checks if tool type is executable', function () {
        expect($this->executionService->isExecutable(ToolType::RestApi))->toBeTrue();
        expect($this->executionService->isExecutable(ToolType::Graphql))->toBeTrue();
        expect($this->executionService->isExecutable(ToolType::Database))->toBeTrue();
        expect($this->executionService->isExecutable(ToolType::Function))->toBeFalse();
        expect($this->executionService->isExecutable(ToolType::Mcp))->toBeFalse();
    });
});

describe('RestApiExecutor', function () {
    it('executes a successful GET request', function () {
        Http::fake([
            'api.example.com/*' => Http::response(['status' => 'ok', 'data' => 'test'], 200),
        ]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [
                'base_url' => 'https://api.example.com',
                'method' => 'GET',
                'path' => '/items/{{id}}',
                'auth_type' => 'none',
            ],
        ]);

        $result = $this->executionService->execute($tool, ['id' => '123']);

        expect($result->success)->toBeTrue();
        expect($result->data)->toBe(['status' => 'ok', 'data' => 'test']);
        expect($result->metadata['method'])->toBe('GET');
    });

    it('executes a POST request with body template', function () {
        Http::fake([
            'api.example.com/*' => Http::response(['created' => true], 201),
        ]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [
                'base_url' => 'https://api.example.com',
                'method' => 'POST',
                'path' => '/items',
                'auth_type' => 'none',
                'request_body_template' => '{"name": "{{name}}", "quantity": {{quantity}}}',
            ],
        ]);

        $result = $this->executionService->execute($tool, [
            'name' => 'Test Item',
            'quantity' => 5,
        ]);

        expect($result->success)->toBeTrue();
        expect($result->data['created'])->toBeTrue();
    });

    it('handles HTTP errors', function () {
        Http::fake([
            'api.example.com/*' => Http::response(['error' => 'Not found'], 404),
        ]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [
                'base_url' => 'https://api.example.com',
                'method' => 'GET',
                'path' => '/items/123',
                'auth_type' => 'none',
            ],
        ]);

        $result = $this->executionService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->statusCode)->toBe(404);
    });

    it('applies bearer authentication', function () {
        Http::fake(function ($request) {
            expect($request->hasHeader('Authorization'))->toBeTrue();
            expect($request->header('Authorization')[0])->toContain('Bearer');

            return Http::response(['authenticated' => true], 200);
        });

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [
                'base_url' => 'https://api.example.com',
                'method' => 'GET',
                'auth_type' => 'bearer',
                'auth_config' => ['token' => 'test-token'],
            ],
        ]);

        $result = $this->executionService->execute($tool, []);

        expect($result->success)->toBeTrue();
    });

    it('maps response fields', function () {
        Http::fake([
            'api.example.com/*' => Http::response([
                'order' => [
                    'id' => '123',
                    'customer' => ['name' => 'John'],
                    'total' => 99.99,
                ],
            ], 200),
        ]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [
                'base_url' => 'https://api.example.com',
                'method' => 'GET',
                'path' => '/orders',
                'auth_type' => 'none',
                'response_mapping' => [
                    'order_id' => 'order.id',
                    'customer_name' => 'order.customer.name',
                    'amount' => 'order.total',
                ],
            ],
        ]);

        $result = $this->executionService->execute($tool, []);

        expect($result->success)->toBeTrue();
        expect($result->data['order_id'])->toBe('123');
        expect($result->data['customer_name'])->toBe('John');
        expect($result->data['amount'])->toBe(99.99);
    });
});

describe('GraphqlExecutor', function () {
    it('executes a GraphQL query', function () {
        Http::fake([
            'api.example.com/graphql' => Http::response([
                'data' => [
                    'order' => [
                        'id' => '123',
                        'status' => 'delivered',
                    ],
                ],
            ], 200),
        ]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Graphql,
            'status' => AgentStatus::Active,
            'config' => [
                'endpoint' => 'https://api.example.com/graphql',
                'operation_type' => 'query',
                'operation' => 'query GetOrder($id: ID!) { order(id: $id) { id status } }',
                'auth_type' => 'none',
            ],
        ]);

        $result = $this->executionService->execute($tool, ['id' => '123']);

        expect($result->success)->toBeTrue();
        expect($result->data['order']['id'])->toBe('123');
        expect($result->metadata['operation_type'])->toBe('query');
    });

    it('handles GraphQL errors', function () {
        Http::fake([
            'api.example.com/graphql' => Http::response([
                'errors' => [
                    ['message' => 'Order not found'],
                    ['message' => 'Invalid ID format'],
                ],
                'data' => null,
            ], 200),
        ]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Graphql,
            'status' => AgentStatus::Active,
            'config' => [
                'endpoint' => 'https://api.example.com/graphql',
                'operation_type' => 'query',
                'operation' => 'query GetOrder($id: ID!) { order(id: $id) { id } }',
                'auth_type' => 'none',
            ],
        ]);

        $result = $this->executionService->execute($tool, ['id' => 'invalid']);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('Order not found');
        expect($result->error)->toContain('Invalid ID format');
    });

    it('uses variables template for parameter mapping', function () {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            expect($body['variables'])->toBe([
                'orderId' => '123',
                'includeItems' => true,
            ]);

            return Http::response(['data' => ['order' => []]], 200);
        });

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Graphql,
            'status' => AgentStatus::Active,
            'config' => [
                'endpoint' => 'https://api.example.com/graphql',
                'operation_type' => 'query',
                'operation' => 'query($orderId: ID!, $includeItems: Boolean) { order(id: $orderId) { id } }',
                'variables_template' => [
                    'orderId' => '{{id}}',
                    'includeItems' => true,
                ],
                'auth_type' => 'none',
            ],
        ]);

        $result = $this->executionService->execute($tool, ['id' => '123']);

        expect($result->success)->toBeTrue();
    });
});

describe('DatabaseExecutor', function () {
    it('blocks dangerous queries in read-only mode', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'user',
                'password' => 'pass',
                'query_template' => 'DELETE FROM users WHERE id = :id',
                'read_only' => true,
            ],
        ]);

        $result = $this->executionService->execute($tool, ['id' => 1]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('disallowed keywords');
    });

    it('blocks INSERT queries in read-only mode', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'mysql',
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'user',
                'password' => 'pass',
                'query_template' => 'INSERT INTO logs (message) VALUES (:message)',
                'read_only' => true,
            ],
        ]);

        $result = $this->executionService->execute($tool, ['message' => 'test']);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('disallowed keywords');
    });

    it('blocks UPDATE queries in read-only mode', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'user',
                'password' => 'pass',
                'query_template' => 'UPDATE users SET name = :name WHERE id = :id',
                'read_only' => true,
            ],
        ]);

        $result = $this->executionService->execute($tool, ['name' => 'Test', 'id' => 1]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('disallowed keywords');
    });

    it('blocks DROP queries in read-only mode', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'user',
                'password' => 'pass',
                'query_template' => 'DROP TABLE users',
                'read_only' => true,
            ],
        ]);

        $result = $this->executionService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('disallowed keywords');
    });

    it('validates required database configuration', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                // Missing host, database, username, query_template
            ],
        ]);

        $errors = $this->executionService->validate($tool, []);

        expect($errors)->toHaveKey('host');
        expect($errors)->toHaveKey('database');
        expect($errors)->toHaveKey('username');
        expect($errors)->toHaveKey('query_template');
    });

    it('allows SELECT queries in read-only mode', function () {
        // This test would need a real database connection
        // For now, we just test that it doesn't block the query
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'test',
                'username' => 'user',
                'password' => 'pass',
                'query_template' => 'SELECT * FROM orders WHERE id = :id',
                'read_only' => true,
            ],
        ]);

        // The query won't actually execute without a real DB, but we can verify
        // it passes the dangerous keywords check
        $executor = app(DatabaseExecutor::class);
        $config = $this->configService->decryptConfig($tool->type, $tool->config);
        $errors = $executor->validate($tool, ['id' => 1], $config);

        expect($errors)->toBeEmpty();
    });
});

describe('Parameter Substitution', function () {
    it('substitutes path parameters', function () {
        Http::fake([
            '*' => Http::response(['id' => '123'], 200),
        ]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [
                'base_url' => 'https://api.example.com',
                'method' => 'GET',
                'path' => '/users/{{user_id}}/orders/{{order_id}}',
                'auth_type' => 'none',
            ],
        ]);

        Http::fake(function ($request) {
            expect($request->url())->toContain('/users/42/orders/123');

            return Http::response(['found' => true], 200);
        });

        $result = $this->executionService->execute($tool, [
            'user_id' => '42',
            'order_id' => '123',
        ]);

        expect($result->success)->toBeTrue();
    });

    it('substitutes body template parameters', function () {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            expect($body['customer'])->toBe('John Doe');
            expect($body['amount'])->toBe('99.99');

            return Http::response(['success' => true], 200);
        });

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [
                'base_url' => 'https://api.example.com',
                'method' => 'POST',
                'path' => '/refunds',
                'auth_type' => 'none',
                'request_body_template' => '{"customer": "{{customer_name}}", "amount": "{{refund_amount}}"}',
            ],
        ]);

        $result = $this->executionService->execute($tool, [
            'customer_name' => 'John Doe',
            'refund_amount' => '99.99',
        ]);

        expect($result->success)->toBeTrue();
    });
});
