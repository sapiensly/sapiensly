<?php

use App\Models\AiCatalogModel;
use App\Models\AppSetting;
use App\Services\Ai\AiDefaults;

beforeEach(function () {
    $this->defaults = app(AiDefaults::class);
});

function catalogModel(string $modelId): AiCatalogModel
{
    return AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => $modelId,
        'label' => $modelId,
        'capability' => 'chat',
        'is_enabled' => true,
    ]);
}

test('primary resolves the stored catalog id to its model string', function () {
    $model = catalogModel('claude-test-x');
    AppSetting::setValue('admin_v2.ai.chat.primary', (string) $model->id);

    expect($this->defaults->primaryId('chat'))->toBe((string) $model->id)
        ->and($this->defaults->primary('chat'))->toBe('claude-test-x');
});

test('candidates order is explicit, primary, fallback, hard default — de-duplicated', function () {
    $primary = catalogModel('p-model');
    $fallback = catalogModel('f-model');
    AppSetting::setValue('admin_v2.ai.chat.primary', (string) $primary->id);
    AppSetting::setValue('admin_v2.ai.chat.fallback', (string) $fallback->id);

    expect($this->defaults->candidates('chat', 'explicit-model'))
        ->toBe(['explicit-model', 'p-model', 'f-model', AiDefaults::HARD_DEFAULT])
        // No explicit override → primary is the resolved single model.
        ->and($this->defaults->model('chat'))->toBe('p-model');
});

test('a module with nothing configured falls back to the hard default', function () {
    expect($this->defaults->primary('flows'))->toBeNull()
        ->and($this->defaults->model('flows'))->toBe(AiDefaults::HARD_DEFAULT);
});

test('withFallback advances to the next candidate when one throws', function () {
    $primary = catalogModel('p-model');
    $fallback = catalogModel('f-model');
    AppSetting::setValue('admin_v2.ai.chat.primary', (string) $primary->id);
    AppSetting::setValue('admin_v2.ai.chat.fallback', (string) $fallback->id);

    $tried = [];
    $result = $this->defaults->withFallback('chat', function (string $model) use (&$tried) {
        $tried[] = $model;
        if ($model === 'p-model') {
            throw new RuntimeException('primary down');
        }

        return "ok:{$model}";
    });

    expect($tried)->toBe(['p-model', 'f-model'])
        ->and($result)->toBe('ok:f-model');
});

test('withFallback rethrows the last error when every candidate fails', function () {
    expect(fn () => $this->defaults->withFallback('flows', fn () => throw new RuntimeException('down')))
        ->toThrow(RuntimeException::class, 'down');
});
