<?php

use App\Services\AiProviderService;

/**
 * MODEL_CATALOGS seeds a fresh install and backs every fixture that asks for
 * "a model this install considers valid". A malformed or chat-less entry is
 * therefore not a cosmetic problem: it either seeds an unusable catalog or
 * makes catalogChatModel() throw across the suite.
 */
it('gives every driver well-formed entries', function () {
    foreach (AiProviderService::MODEL_CATALOGS as $driver => $models) {
        $ids = [];

        foreach ($models as $model) {
            expect($model['id'] ?? '')->toBeString()->not->toBe('', "{$driver} has an entry with no id")
                ->and($model['label'] ?? '')->toBeString()->not->toBe('', "{$driver}: {$model['id']} has no label")
                ->and($model['capabilities'] ?? [])->toBeArray()->not->toBeEmpty("{$driver}: {$model['id']} has no capabilities");

            $ids[] = $model['id'].':'.implode(',', $model['capabilities']);
        }

        expect($ids)->toBe(array_values(array_unique($ids)), "{$driver} repeats a model id");
    }
});

it('gives every syncable driver a chat model to bootstrap from', function () {
    // bootstrapChatModelId() returns null when a driver has no chat entry, and
    // the callers that ask for a valid model id have nothing to fall back to.
    foreach (AiProviderService::SYNCABLE_DRIVERS as $driver) {
        expect(AiProviderService::bootstrapChatModelId($driver))
            ->toBeString("{$driver} has no chat model in its bootstrap catalog");
    }
});

it('keeps the retired OpenAI generation out of the bootstrap', function () {
    // These were still seeded long after OpenAI moved on, so a fresh install
    // offered models the API no longer serves — the drift the sync sweep and
    // this refresh exist to end.
    $ids = array_column(AiProviderService::MODEL_CATALOGS['openai'], 'id');

    expect($ids)->not->toContain('gpt-4o')
        ->and($ids)->not->toContain('gpt-4o-mini')
        ->and($ids)->not->toContain('gpt-4-turbo');
});
