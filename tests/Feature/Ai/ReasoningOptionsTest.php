<?php

use App\Ai\BuilderAgent;
use App\Ai\ChatAgent;
use App\Ai\ExpressGateAgent;
use App\Ai\RuntimeAgent;
use App\Models\User;
use App\Services\Ai\OpenRouterClient;
use App\Services\Ai\ReasoningOptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;

/**
 * Reasoning is off platform-wide by default. Only OpenRouter and OpenAI need an
 * active field to disable it; Anthropic doesn't reason unless thinking is
 * explicitly enabled, so 'off' is its default and a no-op here.
 */
it('translates reasoning modes to the right per-provider field', function () {
    expect(ReasoningOptions::forProvider('off', Lab::OpenRouter))->toBe(['reasoning' => ['enabled' => false]]);
    expect(ReasoningOptions::forProvider('high', Lab::OpenRouter))->toBe(['reasoning' => ['effort' => 'high']]);
    expect(ReasoningOptions::forProvider('off', Lab::OpenAI))->toBe(['reasoning_effort' => 'minimal']);
    expect(ReasoningOptions::forProvider('low', Lab::OpenAI))->toBe(['reasoning_effort' => 'low']);
    // Anthropic off is a no-op (already its default); default/null leave the model's own.
    expect(ReasoningOptions::forProvider('off', Lab::Anthropic))->toBe([]);
    expect(ReasoningOptions::forProvider('default', Lab::OpenRouter))->toBe([]);
    expect(ReasoningOptions::forProvider(null, Lab::OpenRouter))->toBe([]);
});

it('defaults chat and runtime agents to reasoning off, honoring an override', function () {
    expect((new ChatAgent('sys', [], []))->providerOptions(Lab::OpenRouter))
        ->toBe(['reasoning' => ['enabled' => false]]);
    expect((new RuntimeAgent('sys', [], []))->providerOptions(Lab::OpenRouter))
        ->toBe(['reasoning' => ['enabled' => false]]);

    $chat = (new ChatAgent('sys', [], []))->withReasoning('high');
    expect($chat->providerOptions(Lab::OpenRouter))->toBe(['reasoning' => ['effort' => 'high']]);

    // 'default' is the escape hatch back to the model's own behavior.
    $runtime = (new RuntimeAgent('sys', [], []))->withReasoning('default');
    expect($runtime->providerOptions(Lab::OpenRouter))->toBe([]);
});

it('forces reasoning off in the builder and express-gate agents', function () {
    expect((new BuilderAgent('sys', [], []))->providerOptions(Lab::OpenRouter))
        ->toBe(['reasoning' => ['enabled' => false]]);

    $gate = new ExpressGateAgent('instructions', fn ($s) => [], null);
    expect($gate->providerOptions(Lab::OpenRouter))->toBe(['reasoning' => ['enabled' => false]]);
});

it('omits the reasoning-off block for models that mandate reasoning', function () {
    expect(ReasoningOptions::reasoningIsMandatory('anthropic/claude-fable-5'))->toBeTrue()
        ->and(ReasoningOptions::reasoningIsMandatory('anthropic/claude-fable-5:nitro'))->toBeTrue()
        ->and(ReasoningOptions::reasoningIsMandatory('openai/o3-mini'))->toBeTrue()
        ->and(ReasoningOptions::reasoningIsMandatory('o4-mini'))->toBeTrue()
        ->and(ReasoningOptions::reasoningIsMandatory('anthropic/claude-sonnet-4'))->toBeFalse()
        ->and(ReasoningOptions::reasoningIsMandatory(null))->toBeFalse();

    // 'off' sends nothing — the provider default wins instead of a 400.
    expect(ReasoningOptions::forProvider('off', Lab::OpenRouter, 'anthropic/claude-fable-5'))->toBe([])
        ->and(ReasoningOptions::forProvider('off', Lab::OpenAI, 'o3-mini'))->toBe([]);
    // Effort tuning still passes through; non-mandatory models still get the disable.
    expect(ReasoningOptions::forProvider('high', Lab::OpenRouter, 'anthropic/claude-fable-5'))->toBe(['reasoning' => ['effort' => 'high']])
        ->and(ReasoningOptions::forProvider('off', Lab::OpenRouter, 'deepseek/deepseek-chat'))->toBe(['reasoning' => ['enabled' => false]]);
});

it('agents skip the off-block once pinned to a mandatory-reasoning model', function () {
    expect((new ChatAgent('sys', [], []))->forModel('anthropic/claude-fable-5')->providerOptions(Lab::OpenRouter))->toBe([])
        ->and((new RuntimeAgent('sys', [], []))->forModel('anthropic/claude-fable-5')->providerOptions(Lab::OpenRouter))->toBe([])
        ->and((new BuilderAgent('sys', [], []))->forModel('anthropic/claude-fable-5')->providerOptions(Lab::OpenRouter))->toBe([]);

    $gate = (new ExpressGateAgent('instructions', fn ($s) => [], null))->forModel('anthropic/claude-fable-5');
    expect($gate->providerOptions(Lab::OpenRouter))->toBe([]);
});

/**
 * Some endpoints reason unconditionally (e.g. claude-fable-5) and 400 the
 * `reasoning: {enabled: false}` block instead of ignoring it. The client
 * retries once without the block so reasoning-off stays best-effort.
 */
it('detects the reasoning-mandatory rejection only for reasoning requests', function () {
    $mandatory = '{"error":{"message":"Reasoning is mandatory for this endpoint and cannot be disabled.","code":400}}';

    expect(OpenRouterClient::reasoningRejected(400, $mandatory, ['reasoning' => ['enabled' => false]]))->toBeTrue()
        // No reasoning block sent — the 400 is about something else.
        ->and(OpenRouterClient::reasoningRejected(400, $mandatory, []))->toBeFalse()
        // Other 400s with a reasoning block don't trigger a blind retry.
        ->and(OpenRouterClient::reasoningRejected(400, '{"error":{"message":"Invalid model."}}', ['reasoning' => ['enabled' => false]]))->toBeFalse()
        ->and(OpenRouterClient::reasoningRejected(500, $mandatory, ['reasoning' => ['enabled' => false]]))->toBeFalse();
});

it('retries a chat completion without the reasoning block when the endpoint mandates reasoning', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test-123']);
    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('{"error":{"message":"Reasoning is mandatory for this endpoint and cannot be disabled.","code":400}}', 400)
            ->push(['choices' => [['message' => ['content' => 'hola']]]]),
    ]);

    $user = User::factory()->create();
    $response = app(OpenRouterClient::class)->chat(
        $user,
        'anthropic/claude-fable-5',
        [OpenRouterClient::textBlock('hi')],
        OpenRouterClient::reasoningParams('off'),
    );

    expect(OpenRouterClient::text($response))->toBe('hola');

    Http::assertSentCount(2);
    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->data());
    expect($sent[0])->toHaveKey('reasoning')
        ->and($sent[1])->not->toHaveKey('reasoning');
});

it('retries a streamed completion without reasoning and flags the forced reasoning', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test-123']);

    $sse = implode("\n", [
        'data: {"id":"gen-1","model":"anthropic/claude-fable-5","provider":"Anthropic","choices":[{"delta":{"content":"hola"}}]}',
        '',
        'data: {"choices":[{"delta":{},"finish_reason":"stop"}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}',
        '',
        'data: [DONE]',
        '',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::sequence()
            ->push('{"error":{"message":"Reasoning is mandatory for this endpoint and cannot be disabled.","code":400}}', 400)
            ->push($sse),
    ]);

    $user = User::factory()->create();
    $stream = app(OpenRouterClient::class)->chatStreamed(
        $user,
        'anthropic/claude-fable-5',
        [OpenRouterClient::textBlock('hi')],
        OpenRouterClient::reasoningParams('off'),
    );

    expect($stream['text'])->toBe('hola')
        ->and($stream['response']['reasoning_forced_by_provider'])->toBeTrue()
        ->and($stream['response']['usage']['completion_tokens'])->toBe(5);
});

it('does not retry a 400 unrelated to reasoning', function () {
    config(['ai.providers.openrouter.key' => 'sk-or-test-123']);
    Http::fake([
        'openrouter.ai/*' => Http::response('{"error":{"message":"Invalid model."}}', 400),
    ]);

    $user = User::factory()->create();

    expect(fn () => app(OpenRouterClient::class)->chat(
        $user,
        'bogus/model',
        [OpenRouterClient::textBlock('hi')],
        OpenRouterClient::reasoningParams('off'),
    ))->toThrow(RuntimeException::class, 'OpenRouter request failed (400)');

    Http::assertSentCount(1);
});
