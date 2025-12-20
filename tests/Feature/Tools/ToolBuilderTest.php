<?php

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Models\Tool;
use App\Models\User;
use App\Services\ToolBuilderService;
use Prism\Prism\Tool as PrismTool;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->toolBuilder = app(ToolBuilderService::class);
});

describe('buildPrismTools', function () {
    it('builds Prism tools from database tools', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'testdb',
                'username' => 'user',
                'password' => 'pass',
                'query_template' => 'SELECT * FROM users WHERE id = :user_id',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));

        expect($prismTools)->toHaveCount(1);
        expect($prismTools[0])->toBeInstanceOf(PrismTool::class);
    });

    it('filters out non-executable tool types', function () {
        $functionTool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Function,
            'status' => AgentStatus::Active,
        ]);

        $mcpTool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Mcp,
            'status' => AgentStatus::Active,
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$functionTool, $mcpTool]));

        expect($prismTools)->toHaveCount(0);
    });

    it('handles empty collection', function () {
        $prismTools = $this->toolBuilder->buildPrismTools(collect());

        expect($prismTools)->toHaveCount(0);
    });
});

describe('database tool parameters', function () {
    it('extracts named parameters from SQL query', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'User Lookup',
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'testdb',
                'username' => 'user',
                'query_template' => 'SELECT * FROM users WHERE id = :user_id AND status = :status',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));
        $params = $prismTools[0]->parameters();

        expect($params)->toHaveCount(2);
        expect(array_keys($params))->toBe(['user_id', 'status']);
    });

    it('handles queries without parameters', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'testdb',
                'username' => 'user',
                'query_template' => 'SELECT * FROM users',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));
        $params = $prismTools[0]->parameters();

        expect($params)->toHaveCount(0);
    });

    it('deduplicates repeated parameters', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'testdb',
                'username' => 'user',
                'query_template' => 'SELECT * FROM users WHERE id = :user_id OR created_by = :user_id',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));
        $params = $prismTools[0]->parameters();

        expect($params)->toHaveCount(1);
        expect(array_keys($params))->toBe(['user_id']);
    });
});

describe('REST API tool parameters', function () {
    it('extracts parameters from endpoint path', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [
                'base_url' => 'https://api.example.com',
                'endpoint' => '/users/{{user_id}}/orders/{{order_id}}',
                'method' => 'GET',
                'auth_type' => 'none',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));
        $params = $prismTools[0]->parameters();

        expect($params)->toHaveCount(2);
        expect(array_keys($params))->toBe(['user_id', 'order_id']);
    });

    it('extracts parameters from body template', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::RestApi,
            'status' => AgentStatus::Active,
            'config' => [
                'base_url' => 'https://api.example.com',
                'endpoint' => '/users',
                'method' => 'POST',
                'auth_type' => 'none',
                'body_template' => '{"name": "{{name}}", "email": "{{email}}"}',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));
        $params = $prismTools[0]->parameters();

        expect($params)->toHaveCount(2);
        expect(array_keys($params))->toBe(['name', 'email']);
    });
});

describe('GraphQL tool parameters', function () {
    it('extracts parameters from variables template', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => ToolType::Graphql,
            'status' => AgentStatus::Active,
            'config' => [
                'endpoint' => 'https://api.example.com/graphql',
                'query' => 'query GetUser($id: ID!) { user(id: $id) { name } }',
                'variables_template' => '{"id": "{{user_id}}"}',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));
        $params = $prismTools[0]->parameters();

        expect($params)->toHaveCount(1);
        expect(array_keys($params))->toBe(['user_id']);
    });
});

describe('tool name sanitization', function () {
    it('sanitizes tool names with spaces and special characters', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Get User Details!',
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'testdb',
                'username' => 'user',
                'query_template' => 'SELECT * FROM users',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));

        expect($prismTools[0]->name())->toBe('get_user_details');
    });

    it('handles names starting with numbers', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'name' => '123 Tool',
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'testdb',
                'username' => 'user',
                'query_template' => 'SELECT * FROM users',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));

        expect($prismTools[0]->name())->toBe('tool_123_tool');
    });
});

describe('tool description', function () {
    it('uses tool description when available', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'My Tool',
            'description' => 'This tool does amazing things',
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'testdb',
                'username' => 'user',
                'query_template' => 'SELECT 1',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));

        expect($prismTools[0]->description())->toBe('This tool does amazing things');
    });

    it('uses default description when none provided', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'My Tool',
            'description' => null,
            'type' => ToolType::Database,
            'status' => AgentStatus::Active,
            'config' => [
                'driver' => 'pgsql',
                'host' => 'localhost',
                'database' => 'testdb',
                'username' => 'user',
                'query_template' => 'SELECT 1',
            ],
        ]);

        $prismTools = $this->toolBuilder->buildPrismTools(collect([$tool]));

        expect($prismTools[0]->description())->toBe('Execute My Tool');
    });
});
