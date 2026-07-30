<?php

use App\Services\Manifest\ManifestValidator;
use Illuminate\Support\Str;

function portalRailId(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/**
 * The save-time rails on `permissions.public`. Each one refuses a manifest that
 * would have been accepted before, and each refusal names a way a portal could
 * quietly expose more — or less — than its author believed.
 */

/**
 * A manifest with a portal declared: a visitor role, a page granted to it, and
 * an object it may read. The overrides let each test break exactly one thing.
 *
 * @param  array<string, mixed>  $public
 * @param  list<array<string, mixed>>|null  $pagePolicies
 * @param  list<array<string, mixed>>|null  $objectPolicies
 * @return array<string, mixed>
 */
function portalRailManifest(array $public = [], ?array $pagePolicies = null, ?array $objectPolicies = null, array $settings = []): array
{
    $objId = portalRailId('obj');
    $fldName = portalRailId('fld');
    $rolStaff = portalRailId('rol');
    $rolVisitor = portalRailId('rol');
    $pagPublic = portalRailId('pag');

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => portalRailId('app'),
        'slug' => 'portal_app',
        'name' => 'Portal App',
        'version' => 1,
        'objects' => [[
            'id' => $objId, 'slug' => 'tickets', 'name' => 'Ticket',
            'primary_display_field_id' => $fldName,
            'fields' => [['id' => $fldName, 'slug' => 'subject', 'name' => 'Subject', 'type' => 'string']],
        ]],
        'pages' => [[
            'id' => $pagPublic, 'slug' => 'status', 'name' => 'Status', 'path' => '/status',
            'blocks' => [],
        ]],
        'permissions' => [
            'public' => array_replace(
                ['enabled' => true, 'role_id' => $rolVisitor, 'allow_writes' => false],
                $public === [] ? [] : $public,
            ),
            'roles' => [
                ['id' => $rolStaff, 'slug' => 'staff', 'name' => 'Staff', 'is_default' => true],
                ['id' => $rolVisitor, 'slug' => 'visitor', 'name' => 'Visitor', 'is_default' => false],
            ],
            'page_policies' => $pagePolicies ?? [
                ['page_id' => $pagPublic, 'role_id' => $rolVisitor, 'can_view' => true],
            ],
            'object_policies' => $objectPolicies ?? [
                ['object_id' => $objId, 'role_id' => $rolVisitor, 'actions' => ['read']],
            ],
        ],
    ];

    // The caller may need the generated ids; expose them alongside so a test can
    // point a broken policy at a real role.
    $manifest['__ids'] = compact('objId', 'rolStaff', 'rolVisitor', 'pagPublic');

    if ($settings !== []) {
        $manifest['settings'] = $settings;
    }

    return $manifest;
}

/** Strip the test-only id bag before validating. */
function validatePortal(array $manifest): array
{
    $ids = $manifest['__ids'];
    unset($manifest['__ids']);

    return [(new ManifestValidator)->validate($manifest), $ids];
}

it('accepts a well-formed public portal', function () {
    [$result] = validatePortal(portalRailManifest());

    expect($result->valid)->toBeTrue()
        ->and($result->errors)->toBe([]);
});

it('rejects a public role_id that matches no declared role', function () {
    [$result] = validatePortal(portalRailManifest(public: ['role_id' => portalRailId('rol')]));

    expect($result->valid)->toBeFalse()
        ->and(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('refuses to hand the internet the role members fall back to', function () {
    $manifest = portalRailManifest();
    $manifest['permissions']['public']['role_id'] = $manifest['__ids']['rolStaff'];
    // ...and grant the staff role the page, so only the is_default rail can fire.
    $manifest['permissions']['page_policies'] = [
        ['page_id' => $manifest['__ids']['pagPublic'], 'role_id' => $manifest['__ids']['rolStaff'], 'can_view' => true],
    ];

    [$result] = validatePortal($manifest);

    expect($result->valid)->toBeFalse()
        ->and(collect($result->errors)->pluck('code'))->toContain('public_role_is_default');
});

it('rejects a portal that would show a visitor nothing', function () {
    [$result] = validatePortal(portalRailManifest(pagePolicies: []));

    expect($result->valid)->toBeFalse()
        ->and(collect($result->errors)->pluck('code'))->toContain('public_portal_has_no_pages');
});

it('rejects write grants on a read-only portal', function () {
    $manifest = portalRailManifest();
    $manifest['permissions']['object_policies'] = [[
        'object_id' => $manifest['__ids']['objId'],
        'role_id' => $manifest['__ids']['rolVisitor'],
        'actions' => ['read', 'create'],
    ]];

    [$result] = validatePortal($manifest);

    expect($result->valid)->toBeFalse()
        ->and(collect($result->errors)->pluck('code'))->toContain('public_write_without_optin');
});

it('accepts the same write grants once allow_writes is on', function () {
    $manifest = portalRailManifest(public: ['allow_writes' => true]);
    $manifest['permissions']['object_policies'] = [[
        'object_id' => $manifest['__ids']['objId'],
        'role_id' => $manifest['__ids']['rolVisitor'],
        'actions' => ['read', 'create'],
    ]];

    [$result] = validatePortal($manifest);

    expect($result->valid)->toBeTrue()
        ->and($result->errors)->toBe([]);
});

it('rejects a portal declared on a landing surface', function () {
    [$result] = validatePortal(portalRailManifest(settings: ['surface' => 'landing']));

    expect($result->valid)->toBeFalse()
        ->and(collect($result->errors)->pluck('code'))->toContain('public_on_landing');
});

it('leaves a disabled portal block alone apart from resolving its role', function () {
    // enabled:false is a draft the author has not opened yet — the page/write
    // rails must not block saving it, but a dangling role must still be caught.
    [$result] = validatePortal(portalRailManifest(public: ['enabled' => false], pagePolicies: []));

    expect($result->valid)->toBeTrue();
});
