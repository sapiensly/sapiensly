<?php

use App\Ai\BuilderAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * A builder turn is not one model call. It is a LOOP: the model calls a tool, we
 * hand back the result, it calls another, and so on — nine tool calls in the build
 * this test was written for. Every one of those is a paid round-trip, with a
 * context that grows each time.
 *
 * Under laravel/ai 0.8.1 the streaming path threw all of that away: each step
 * computed its usage, and a step that ended in `tool_use` recursed into the next
 * one without ever emitting it. Only the FINAL step — the one that stops talking
 * to tools — reached the recorder. A real build reported 103 output tokens and
 * $0.0242, which is what the closing sentence cost, not what the build cost.
 *
 * v0.9.0 accumulates across steps. This is the guard that says so, and that would
 * fail the day the sum quietly becomes the last step again — a regression nothing
 * else would catch, because an under-reported cost looks exactly like a cheap
 * build.
 */
class UsageProbeTool implements Tool
{
    public function name(): string
    {
        return 'probe';
    }

    public function description(): string
    {
        return 'A tool that exists only so the model takes a second step.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(ToolRequest $request): string
    {
        return 'ok';
    }
}

/**
 * One Anthropic SSE step. A step that calls a tool stops with `tool_use`; the last
 * one stops with `end_turn`.
 */
function sseStep(int $input, int $cacheRead, int $output, bool $callsTool): string
{
    $start = json_encode(['type' => 'message_start', 'message' => [
        'model' => 'claude-haiku-4-5-20251001',
        'usage' => ['input_tokens' => $input, 'cache_read_input_tokens' => $cacheRead, 'cache_creation_input_tokens' => 0],
    ]]);

    $body = "event: message_start\ndata: {$start}\n\n";

    if ($callsTool) {
        $block = json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => [
            'type' => 'tool_use', 'id' => 'toolu_probe', 'name' => 'probe',
        ]]);
        $delta = json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{}']]);
        $stop = json_encode(['type' => 'content_block_stop', 'index' => 0]);
        $body .= "event: content_block_start\ndata: {$block}\n\n";
        $body .= "event: content_block_delta\ndata: {$delta}\n\n";
        $body .= "event: content_block_stop\ndata: {$stop}\n\n";
        $reason = 'tool_use';
    } else {
        $block = json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]);
        $delta = json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'listo']]);
        $stop = json_encode(['type' => 'content_block_stop', 'index' => 0]);
        $body .= "event: content_block_start\ndata: {$block}\n\n";
        $body .= "event: content_block_delta\ndata: {$delta}\n\n";
        $body .= "event: content_block_stop\ndata: {$stop}\n\n";
        $reason = 'end_turn';
    }

    $msgDelta = json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => $reason], 'usage' => ['output_tokens' => $output]]);
    $body .= "event: message_delta\ndata: {$msgDelta}\n\n";
    $body .= "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";

    return $body;
}

it('a streamed turn bills every step of the loop, not just the last one', function () {
    config(['ai.providers.anthropic.key' => 'sk-test']);

    // Step 1 calls the tool (1,000 in / 200 out). Step 2 closes the turn
    // (1,500 in / 50 out). The turn cost BOTH.
    Http::fake([
        '*' => Http::sequence()
            ->push(sseStep(input: 1000, cacheRead: 100, output: 200, callsTool: true), 200)
            ->push(sseStep(input: 1500, cacheRead: 300, output: 50, callsTool: false), 200),
    ]);

    $agent = new BuilderAgent(
        instructions: 'You are a test agent.',
        messages: [],
        tools: [new UsageProbeTool],
    );

    $stream = $agent->stream('haz algo', provider: Lab::Anthropic, model: 'claude-haiku-4-5-20251001');

    $end = null;
    foreach ($stream as $event) {
        if ($event instanceof StreamEnd) {
            $end = $event;
        }
    }

    expect($end)->not->toBeNull();

    // The number that used to be reported was the last step alone: 50 output
    // tokens, and a bill for the closing sentence.
    expect($end->usage->completionTokens)->toBe(250)      // 200 + 50
        ->and($end->usage->promptTokens)->toBe(2500)      // 1000 + 1500
        ->and($end->usage->cacheReadInputTokens)->toBe(400); // 100 + 300
});
