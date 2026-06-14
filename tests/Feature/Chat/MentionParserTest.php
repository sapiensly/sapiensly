<?php

use App\Models\Agent;
use App\Models\User;
use App\Services\Chat\MentionParser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->parser = app(MentionParser::class);
});

function makeAgent(User $user, string $name): Agent
{
    return Agent::factory()->forUser($user)->active()->create(['name' => $name]);
}

it('resolves explicit ids, in mention order, deduplicated', function () {
    $a = makeAgent($this->user, 'Finance');
    $b = makeAgent($this->user, 'Sales');
    $c = makeAgent($this->user, 'Legal');

    $result = $this->parser->resolve($this->user, [$c->id, $a->id, $a->id, $b->id]);

    expect($result['agents']->pluck('id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and($result['capped'])->toBeFalse();
});

it('caps at five agents and flags it', function () {
    $ids = collect(range(1, 6))
        ->map(fn ($i) => makeAgent($this->user, "Agent {$i}")->id)
        ->all();

    $result = $this->parser->resolve($this->user, $ids);

    expect($result['agents'])->toHaveCount(MentionParser::MAX_AGENTS)
        ->and($result['capped'])->toBeTrue();
});

it('silently ignores unknown ids and agents from other users', function () {
    $mine = makeAgent($this->user, 'Mine');
    $other = makeAgent(User::factory()->create(), 'Theirs');

    $result = $this->parser->resolve($this->user, ['agent_missing', $other->id, $mine->id]);

    expect($result['agents']->pluck('id')->all())->toBe([$mine->id]);
});

it('falls back to @name tokens in the raw text', function () {
    $finance = makeAgent($this->user, 'Finanzas');
    makeAgent($this->user, 'Ventas');

    $result = $this->parser->resolve($this->user, [], '@Finanzas should we ship?');

    expect($result['agents']->pluck('id')->all())->toBe([$finance->id]);
});
