<?php

use App\Services\Workflows\TriggerFilterMatcher;

function matcherObject(): array
{
    return [
        'id' => 'obj_1',
        'fields' => [
            ['id' => 'fld_status', 'slug' => 'status', 'type' => 'single_select'],
            ['id' => 'fld_amount', 'slug' => 'amount', 'type' => 'number'],
            ['id' => 'fld_title', 'slug' => 'title', 'type' => 'string'],
            ['id' => 'fld_tags', 'slug' => 'tags', 'type' => 'multi_select'],
            ['id' => 'fld_active', 'slug' => 'active', 'type' => 'boolean'],
            ['id' => 'fld_note', 'slug' => 'note', 'type' => 'string'],
        ],
    ];
}

function matcherRecord(): array
{
    return [
        'id' => 'rec_1',
        'data' => [
            'status' => 'open',
            'amount' => 150,
            'title' => 'Refund Request',
            'tags' => ['vip', 'urgent'],
            'active' => true,
            'note' => null,
        ],
        'created_at' => '2026-06-30T10:00:00Z',
    ];
}

function matches(array $filter): bool
{
    return (new TriggerFilterMatcher)->matches($filter, matcherObject(), matcherRecord());
}

it('matches eq / neq on a scalar field', function () {
    expect(matches(['op' => 'eq', 'field_id' => 'fld_status', 'value' => 'open']))->toBeTrue()
        ->and(matches(['op' => 'eq', 'field_id' => 'fld_status', 'value' => 'closed']))->toBeFalse()
        ->and(matches(['op' => 'neq', 'field_id' => 'fld_status', 'value' => 'closed']))->toBeTrue();
});

it('compares numbers numerically, not lexically', function () {
    expect(matches(['op' => 'gt', 'field_id' => 'fld_amount', 'value' => 100]))->toBeTrue()
        ->and(matches(['op' => 'gt', 'field_id' => 'fld_amount', 'value' => 200]))->toBeFalse()
        ->and(matches(['op' => 'lte', 'field_id' => 'fld_amount', 'value' => 150]))->toBeTrue()
        ->and(matches(['op' => 'between', 'field_id' => 'fld_amount', 'value' => [100, 200]]))->toBeTrue()
        ->and(matches(['op' => 'between', 'field_id' => 'fld_amount', 'value' => [200, 300]]))->toBeFalse();
});

it('does case-insensitive text ops', function () {
    expect(matches(['op' => 'contains', 'field_id' => 'fld_title', 'value' => 'refund']))->toBeTrue()
        ->and(matches(['op' => 'starts_with', 'field_id' => 'fld_title', 'value' => 'REFUND']))->toBeTrue()
        ->and(matches(['op' => 'ends_with', 'field_id' => 'fld_title', 'value' => 'request']))->toBeTrue()
        ->and(matches(['op' => 'contains', 'field_id' => 'fld_title', 'value' => 'invoice']))->toBeFalse();
});

it('handles in / not_in over scalars and multi-select arrays', function () {
    expect(matches(['op' => 'in', 'field_id' => 'fld_status', 'value' => ['open', 'pending']]))->toBeTrue()
        ->and(matches(['op' => 'not_in', 'field_id' => 'fld_status', 'value' => ['closed']]))->toBeTrue()
        ->and(matches(['op' => 'in', 'field_id' => 'fld_tags', 'value' => ['x', 'urgent']]))->toBeTrue()
        ->and(matches(['op' => 'contains', 'field_id' => 'fld_tags', 'value' => 'vip']))->toBeTrue()
        ->and(matches(['op' => 'eq', 'field_id' => 'fld_tags', 'value' => 'vip']))->toBeTrue();
});

it('treats a NULL field as not matching a value comparison, and an empty value as a no-op', function () {
    // note is null → eq with a value never matches
    expect(matches(['op' => 'eq', 'field_id' => 'fld_note', 'value' => 'x']))->toBeFalse()
        // empty comparison value does not constrain → matches
        ->and(matches(['op' => 'eq', 'field_id' => 'fld_status', 'value' => '']))->toBeTrue();
});

it('handles is_null / is_not_null', function () {
    expect(matches(['op' => 'is_null', 'field_id' => 'fld_note']))->toBeTrue()
        ->and(matches(['op' => 'is_not_null', 'field_id' => 'fld_status']))->toBeTrue()
        ->and(matches(['op' => 'is_null', 'field_id' => 'fld_status']))->toBeFalse();
});

it('combines conditions with and / or / not', function () {
    expect(matches(['op' => 'and', 'conditions' => [
        ['op' => 'eq', 'field_id' => 'fld_status', 'value' => 'open'],
        ['op' => 'gte', 'field_id' => 'fld_amount', 'value' => 100],
    ]]))->toBeTrue()
        ->and(matches(['op' => 'and', 'conditions' => [
            ['op' => 'eq', 'field_id' => 'fld_status', 'value' => 'open'],
            ['op' => 'gt', 'field_id' => 'fld_amount', 'value' => 999],
        ]]))->toBeFalse()
        ->and(matches(['op' => 'or', 'conditions' => [
            ['op' => 'eq', 'field_id' => 'fld_status', 'value' => 'closed'],
            ['op' => 'gt', 'field_id' => 'fld_amount', 'value' => 100],
        ]]))->toBeTrue()
        ->and(matches(['op' => 'not', 'condition' => ['op' => 'eq', 'field_id' => 'fld_status', 'value' => 'closed']]))->toBeTrue();
});

it('reads system fields from the record payload', function () {
    expect(matches(['op' => 'gt', 'field_id' => 'sys_created_at', 'value' => '2026-01-01']))->toBeTrue()
        ->and(matches(['op' => 'lt', 'field_id' => 'sys_created_at', 'value' => '2026-01-01']))->toBeFalse();
});

it('returns false for an unknown field id', function () {
    expect(matches(['op' => 'eq', 'field_id' => 'fld_missing', 'value' => 'x']))->toBeFalse();
});
