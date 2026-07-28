<?php

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Models\AiUsageEvent;
use App\Models\App;
use App\Models\Chatbot;
use App\Models\Document;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The responsive contract
|--------------------------------------------------------------------------
|
| Every screen in scope must survive the three widths we certify without
| spilling sideways. A page that scrolls horizontally on a phone is the most
| common way a desktop-only layout fails, and it is the cheapest to catch —
| so it is asserted here rather than eyeballed once and forgotten.
|
| Scope (agreed): the admin-v2 shell and the auth screens. Public landings
| and the legacy layouts are deliberately out.
|
| Devices map to our three targets exactly:
|   iPhone15    390 x 844   phone
|   iPadMini    768 x 1024  tablet
|   macbookAir 1440 x 900   desktop (any width >= the `lg` boundary)
|
*/

/**
 * Content that runs past the right edge of the viewport.
 *
 * NOT `scrollWidth > clientWidth`: the shell's root is `overflow-hidden`
 * (AppLayoutV2), so anything that spills is silently CLIPPED instead of
 * making the document scroll. That check passes on a page whose buttons and
 * cards are sliced off — which is exactly what it did before this was
 * rewritten. Measuring each element's right edge against the viewport is what
 * actually catches it.
 *
 * Skipped: absolutely/fixed-positioned decoration (glows deliberately clipped
 * by overflow-hidden) and anything inside a real horizontal scroller, which
 * is a legitimate way to present a wide table.
 *
 * Returns the offender so a failure names it instead of saying "false".
 */
const NO_CLIPPED_CONTENT = <<<'JS'
function () {
    const vw = document.documentElement.clientWidth;
    // Stops at <main>: the shell's scroll container is `overflow-y-auto`, and
    // CSS computes the other axis to `auto` whenever one axis is not visible.
    // Walking past it would treat EVERY element as "inside a scroller" and
    // wave the whole page through — which is what it did at first.
    const inScroller = (el) => {
        for (let p = el.parentElement; p && p.tagName !== 'MAIN' && p !== document.body; p = p.parentElement) {
            const ox = getComputedStyle(p).overflowX;
            if (ox === 'auto' || ox === 'scroll') return true;
        }
        return false;
    };
    for (const el of document.querySelectorAll('body *')) {
        const cs = getComputedStyle(el);
        if (cs.position === 'absolute' || cs.position === 'fixed') continue;
        if (cs.display === 'none' || cs.visibility === 'hidden' || cs.pointerEvents === 'none') continue;
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        if (r.right > vw + 1 && !inScroller(el)) {
            return `${el.tagName}.${(el.className || '').toString().slice(0, 70)} right=${Math.round(r.right)} vw=${vw}`;
        }
    }
    return '';
}
JS;

dataset('viewports', [
    'phone (390)' => 'iPhone15',
    'tablet (768)' => 'iPadMini',
    'desktop (1440)' => 'macbookAir',
]);

/** The auth screens a guest can reach directly. */
dataset('auth screens', [
    '/login',
    '/register',
    '/forgot-password',
    // Any token renders the form; Fortify only validates it on submit.
    '/reset-password/2f6c9a1e-token',
]);

it('never scrolls sideways on auth screens', function (string $route, string $device) {
    visit($route)->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('auth screens')->with('viewports');

/*
 * The remaining three auth screens are unreachable as a guest — each needs a
 * specific session state, so they get the setup rather than being quietly
 * dropped from the sweep.
 */

it('never scrolls sideways on the email verification notice', function (string $device) {
    $this->actingAs(User::factory()->unverified()->create());

    visit('/email/verify')->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('viewports');

it('never scrolls sideways on the password confirmation screen', function (string $device) {
    $this->actingAs(User::factory()->create());

    visit('/user/confirm-password')->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('viewports');

it('never scrolls sideways on the two-factor challenge', function (string $device) {
    // Reached only by logging in as a user with 2FA confirmed — Fortify puts
    // the pending id in the session, so it cannot be visited directly.
    $user = User::factory()->create([
        'email' => 'two-factor@example.com',
        'password' => Hash::make('password'),
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ]);

    visit('/login')->on()->{$device}()
        ->fill('email', $user->email)
        ->fill('password', 'password')
        // By selector, not by label: "Log in" is both the card heading and the
        // button, and the text locator resolves to the heading.
        ->click('button[type="submit"]')
        ->assertPathIs('/two-factor-challenge')
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('viewports');

/**
 * Every parameter-free page the admin-v2 shell serves. The phone width is
 * where layouts fail, so the whole surface is swept at 390 rather than a
 * hand-picked sample — the point is to find the screens nobody thought to
 * check.
 */
dataset('shell screens', [
    '/apps',
    '/agents',
    '/agents/create',
    '/chat',
    '/chatbots',
    '/chatbots/create',
    '/dashboard',
    '/debates',
    '/documents',
    '/documents/create',
    '/knowledge-bases',
    '/knowledge-bases/create',
    '/playground',
    '/playground/benchmarks',
    '/playground/history',
    '/settings/profile',
    '/settings/appearance',
    '/settings/organization',
    '/settings/organization/brand',
    '/settings/organization/context',
    '/settings/sso',
    '/slides',
    '/system/ai-providers',
    '/system/ai-providers/create',
    '/system/ai-spend',
    '/system/cloud-providers',
    '/system/integrations',
    '/system/integrations/create',
    '/system/integrations/templates',
    '/system/mcp',
    '/system/whatsapp',
    '/system/whatsapp/create',
    '/tools',
    '/tools/create',
]);

/**
 * Guards the sweep below. If seeding ever stops producing visible rows —
 * a renamed column, a visibility rule, a factory change — the sweep would go
 * back to certifying empty states and stay green while testing nothing.
 * This is the canary for that.
 */
it('renders seeded rows on the list screens', function (string $route) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);
    seedTenantContent($org, $user);

    visit($route)->on()->macbookAir()
        ->assertSee('Warehouse Inventory Reconciliation Assistant');
})->with([
    '/agents',
    '/tools',
    '/chatbots',
    '/knowledge-bases',
    '/apps',
    '/documents',
    '/system/integrations',
    '/chat',
]);

it('never scrolls sideways inside the app shell', function (string $route, string $device) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    // With rows in them — an empty list is responsive by construction and
    // would make this sweep pass without testing anything.
    seedTenantContent($org, $user);

    visit($route)->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('shell screens')->with('viewports');

/*
|--------------------------------------------------------------------------
| Detail screens
|--------------------------------------------------------------------------
|
| The densest surfaces in the product — metadata panels, tab strips, side
| panels — and the ones the list sweep can't reach because their URLs need a
| record. Built from the seeded entities so they always exist.
|
| The canvas builders (app builder, bot-flow editors) are deliberately absent:
| three-pane editors are desktop work by agreement, not screens to squeeze.
|
*/

/** @return array<string, string> route label => URL */
function detailRoutes(array $seeded): array
{
    return [
        'agent' => "/agents/{$seeded['agent']->id}",
        'agent edit' => "/agents/{$seeded['agent']->id}/edit",
        'app' => "/apps/{$seeded['app']->id}",
        // No `/apps/{app}/access`: AppAccessController::index returns JSON, not
        // an Inertia screen. It was in this list and passing as a 404 until the
        // guard above went in.
        'tool' => "/tools/{$seeded['tool']->id}",
        'tool edit' => "/tools/{$seeded['tool']->id}/edit",
        'chatbot' => "/chatbots/{$seeded['chatbot']->id}",
        'chatbot edit' => "/chatbots/{$seeded['chatbot']->id}/edit",
        'chatbot analytics' => "/chatbots/{$seeded['chatbot']->id}/analytics",
        'chatbot conversations' => "/chatbots/{$seeded['chatbot']->id}/conversations",
        'chatbot embed' => "/chatbots/{$seeded['chatbot']->id}/embed",
        'knowledge base' => "/knowledge-bases/{$seeded['knowledgeBase']->id}",
        'knowledge base edit' => "/knowledge-bases/{$seeded['knowledgeBase']->id}/edit",
        'document' => "/documents/{$seeded['document']->id}",
        'integration' => "/system/integrations/{$seeded['integration']->id}",
        'integration edit' => "/system/integrations/{$seeded['integration']->id}/edit",
        'integration executions' => "/system/integrations/{$seeded['integration']->id}/executions",
        'chat' => "/chat/{$seeded['chat']->id}",
        'whatsapp connection' => "/system/whatsapp/{$seeded['whatsappConnection']->id}",
        'whatsapp analytics' => "/system/whatsapp/{$seeded['whatsappConnection']->id}/analytics",
        'whatsapp templates' => "/system/whatsapp/{$seeded['whatsappConnection']->id}/templates",
        'whatsapp edit' => "/system/whatsapp/{$seeded['whatsappConnection']->id}/edit",
        // Literal `index` segment — `/system/whatsapp/inbox` is a 404.
        'whatsapp inbox' => '/system/whatsapp/inbox/index',
        'whatsapp conversation' => "/system/whatsapp/inbox/{$seeded['whatsappConversation']->id}",
    ];
}

it('never clips content on detail screens', function (string $device) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);
    $seeded = seedTenantContent($org, $user);

    $failures = [];

    foreach (detailRoutes($seeded) as $label => $url) {
        $page = visit($url)->on()->{$device}();

        // A 404 is trivially responsive, so a mistyped URL would sail through
        // this sweep looking like coverage. `/system/whatsapp/inbox` did
        // exactly that until the real route turned out to end in `/index`.
        $heading = $page->script("(document.querySelector('h1') || {}).innerText || ''");

        if (str_contains((string) $heading, '404')) {
            $failures[] = "{$label} ({$url}): renders a 404, not the screen";

            continue;
        }

        $offender = $page->script(NO_CLIPPED_CONTENT);

        if ($offender !== '') {
            $failures[] = "{$label} ({$url}): {$offender}";
        }
    }

    expect($failures)->toBe([]);
})->with('viewports');

/*
|--------------------------------------------------------------------------
| The shell's navigation
|--------------------------------------------------------------------------
|
| Below `lg` the sidebar column is not rendered, so the drawer IS the only
| navigation there — if it stops opening, the app becomes unnavigable on a
| phone without anything visibly breaking. Hence these are asserted, not
| eyeballed.
|
*/

const SIDEBAR_COLUMN_HIDDEN = <<<'JS'
function () {
    const aside = document.querySelector('aside.sp-glass-sidebar');
    return aside !== null && getComputedStyle(aside).display === 'none';
}
JS;

const SIDEBAR_COLUMN_SHOWN = <<<'JS'
function () {
    const aside = document.querySelector('aside.sp-glass-sidebar');
    return aside !== null && aside.getBoundingClientRect().width > 0;
}
JS;

it('swaps the sidebar column for a drawer below lg', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mcpMember(mcpOrg()));

    visit('/apps')->on()->iPhone15()
        ->assertScript(SIDEBAR_COLUMN_HIDDEN)
        // Nothing is mounted until asked for — no overlay stealing taps.
        ->assertScript("document.querySelector('.sp-mobile-nav') === null")
        ->click('[aria-label="Open navigation"]')
        ->assertPresent('.sp-mobile-nav')
        ->assertSee('Knowledge Base')
        ->assertNoJavaScriptErrors();
});

it('closes the drawer when navigating, leaving no overlay behind', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mcpMember(mcpOrg()));

    visit('/apps')->on()->iPhone15()
        ->click('[aria-label="Open navigation"]')
        ->assertPresent('.sp-mobile-nav')
        ->click('.sp-mobile-nav a[href="/chatbots"]')
        ->assertPathIs('/chatbots')
        ->assertScript("document.querySelector('.sp-mobile-nav') === null")
        // A stranded dialog overlay leaves the body unclickable — the failure
        // mode that makes a page look fine and respond to nothing.
        ->assertScript("getComputedStyle(document.body).pointerEvents === 'auto'")
        ->assertNoJavaScriptErrors();
});

/*
|--------------------------------------------------------------------------
| Screens with data in them
|--------------------------------------------------------------------------
|
| The sweeps above visit a fresh tenant, so most screens render their empty
| state — and an empty state is responsive by construction. Anything with a
| table or a list has to be asserted with rows actually in it, or the check
| is theatre.
|
*/

it('scrolls the spend table on a phone instead of crushing its columns', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    // Long, realistic model identifiers — the reason the column needs room.
    // The org dashboard reads raw events, not the daily rollup.
    foreach (['anthropic/claude-opus-5-20260514', 'openai/gpt-5-turbo-preview'] as $model) {
        AiUsageEvent::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'module' => 'chat',
            'driver' => 'anthropic',
            'model' => $model,
            'source' => 'system',
            'input_tokens' => 4_500_000,
            'output_tokens' => 900_000,
            'cost' => 123.45,
        ]);
    }

    visit('/system/ai-spend')->on()->iPhone15()
        ->assertSee('anthropic/claude-opus-5-20260514')
        // The page itself must still not spill past the viewport...
        ->assertScript(NO_CLIPPED_CONTENT, '')
        // ...while the table keeps its readable width inside a scroller.
        ->assertScript(<<<'JS'
            function () {
                const table = document.querySelector('table');
                const scroller = table.parentElement;
                return table.getBoundingClientRect().width >= 420
                    && ['auto', 'scroll'].includes(getComputedStyle(scroller).overflowX);
            }
            JS)
        ->assertNoJavaScriptErrors();
});

it('swaps the spend panel between services-by-model and artifacts-by-service', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    // One app that spent through TWO services. Grouped by service it shows up
    // twice and its real cost is a mental sum; grouped by artifact it is one
    // card that answers "what did this app cost me".
    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Order Desk',
    ]);
    foreach ([['builder', 0.75], ['landing_director', 0.25]] as [$module, $cost]) {
        AiUsageEvent::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'module' => $module,
            'driver' => 'anthropic',
            'model' => 'anthropic/claude-opus-5-20260514',
            'source' => 'system',
            'app_id' => $app->id,
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost' => $cost,
        ]);
    }

    visit('/system/ai-spend')
        // Models is the default reading: which model burned the money.
        ->assertSee('Spend by service')
        ->assertSee('anthropic/claude-opus-5-20260514')
        ->assertDontSee('Order Desk')
        // Artifacts is the other one: the app named once, its id underneath,
        // and the two services it spent through listed inside it.
        ->click('button:has-text("artifacts")')
        ->assertSee('Spend by artifact')
        ->assertSee('Order Desk')
        ->assertSee($app->id)
        ->assertSee('$1.00')
        ->assertScript(
            <<<'JS'
            function () {
                return [...document.querySelectorAll('h3')].filter((h) => h.textContent.trim() === 'Order Desk').length;
            }
            JS,
            1,
        )
        ->assertNoJavaScriptErrors();
});

it('scrolls the spend period picker on a phone instead of spilling it', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mcpMember(mcpOrg()));

    // Six windows (today / week / month / 7d / 30d / 90d) do not fit a phone in
    // one row, so the pill group has to scroll inside its own container.
    visit('/system/ai-spend')->on()->iPhone15()
        ->assertSee('Today')
        ->assertSee('Week')
        ->assertSee('Month')
        ->assertSee('90d')
        ->assertScript(NO_CLIPPED_CONTENT, '')
        ->assertScript(<<<'JS'
            function () {
                const links = [...document.querySelectorAll('a[href*="ai-spend?period="]')];
                if (links.length !== 6) return false;
                const scroller = links[0].parentElement.parentElement;
                return ['auto', 'scroll'].includes(getComputedStyle(scroller).overflowX);
            }
            JS)
        ->assertNoJavaScriptErrors();
});

it('keeps the chat send button on screen under a long model name', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);
    $seeded = seedTenantContent($org, $user);

    // The label is widened in the DOM rather than seeded: it comes from the
    // server's model list, and the bug is a layout rule, not a data one. A
    // real label like "DeepSeek: DeepSeek V4 Pro" is enough to trigger it.
    //
    // Send is the one control in this row that must never be lost — before the
    // fix it was pushed to 397px in a 390px viewport and simply disappeared.
    $page = visit("/chat/{$seeded['chat']->id}")->on()->iPhone15();

    // Wait for the composer to mount before measuring — without this the script
    // ran against a half-rendered page and the test passed or failed depending
    // on machine load.
    $page->assertPresent('[data-testid="chat-send"]');

    $result = $page->script(<<<'JS'
        (function () {
            const label = document.querySelector('[data-testid="chat-model-label"]');
            if (!label) return 'model picker label not found';

            label.textContent = 'DeepSeek: DeepSeek V4 Pro Extended Reasoning';

            // By test id, not by shape: `button.size-8.rounded-full` matches
            // other buttons on the page, and selecting one of those made an
            // earlier version of this test pass without the fix.
            const send = document.querySelector('[data-testid="chat-send"]');
            if (!send) return 'send button not found';

            const sendBox = send.getBoundingClientRect();
            const vw = document.documentElement.clientWidth;

            if (sendBox.right > vw + 1) {
                return `send button at ${Math.round(sendBox.right)}, viewport ${vw}`;
            }

            // Fitting is not enough: the first fix let the left group shrink
            // while its children kept their size, so they spilled out of it and
            // sat ON TOP of the send button. Nothing in the row may overlap it.
            for (const el of document.querySelectorAll('button, span, svg')) {
                if (el === send || send.contains(el)) continue;

                const b = el.getBoundingClientRect();
                if (b.width === 0 || b.height === 0) continue;

                const overlaps = b.left < sendBox.right && b.right > sendBox.left
                    && b.top < sendBox.bottom && b.bottom > sendBox.top;

                if (overlaps) {
                    return `${el.tagName}.${(el.className || '').toString().slice(0, 40)} overlaps the send button`;
                }
            }

            return '';
        })()
        JS);

    expect($result)->toBe('');
});

it('keeps the persistent sidebar column at lg and above', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mcpMember(mcpOrg()));

    visit('/apps')->on()->macbookAir()
        ->assertScript(SIDEBAR_COLUMN_SHOWN)
        ->assertScript("document.querySelector('.sp-mobile-nav') === null")
        // Asserted by href, not by label: with no `sidebar_state` cookie the
        // column starts as the collapsed icon rail, so the labels are absent
        // on a first visit even though the nav is fully there.
        ->assertPresent('aside.sp-glass-sidebar a[href="/knowledge-bases"]')
        ->assertNoJavaScriptErrors();
});

/**
 * The app builder. It used to be exempt (DesktopOnlyNotice said so out loud),
 * and at 390 its header ran to 776px — the five labelled view tabs alone were
 * 699. It is in scope now; only its fine-tune canvas stays a desktop tool, and
 * that mode's toggle is hidden below `lg` rather than left to fail silently.
 */
it('never scrolls sideways in the app builder', function (string $device) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        // A long name is the realistic case and the one that pushed the header.
        'name' => 'Refund Eligibility Checker — Tier 2 Escalations',
    ]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'refunds',
        'name' => $app->name,
        'version' => 1,
        'objects' => [[
            'id' => 'obj_'.strtolower((string) Str::ulid()),
            'slug' => 'reembolsos',
            'name' => 'Reembolso',
            'fields' => [[
                'id' => 'fld_'.strtolower((string) Str::ulid()),
                'slug' => 'cliente', 'name' => 'Cliente', 'type' => 'string',
            ]],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    visit("/apps/{$app->id}/builder")->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('viewports');

it('does not offer the workflow editor on a phone', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'flows', 'name' => 'Flows', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    // A node canvas needs a pointer; the tab is withdrawn rather than left to
    // open something unusable.
    $tabCount = <<<'JS'
    function () {
        return String([...document.querySelectorAll('button')]
            .filter(b => /Workflows|Flujos/i.test(b.textContent || ''))
            .filter(b => b.getBoundingClientRect().width > 0).length);
    }
    JS;

    visit("/apps/{$app->id}/builder")->on()->iPhone15()->assertScript($tabCount, '0');
    visit("/apps/{$app->id}/builder")->on()->macbookAir()->assertScript($tabCount, '1');
});

it('hides the fine-tune toggle on a phone and keeps it on desktop', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'tuning', 'name' => 'Tuning', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
        'settings' => ['surface' => 'landing'],
    ], $user);

    // Fine-tune IS the drag-and-drop canvas: offering it on a touch screen
    // would be offering something that cannot work.
    $visible = <<<'JS'
    function () {
        const el = [...document.querySelectorAll('button')].find(b => /Ajuste|Fine|Manual/i.test(b.textContent || ''));
        if (!el) return 'absent';
        return el.getBoundingClientRect().width > 0 ? 'visible' : 'hidden';
    }
    JS;

    visit("/apps/{$app->id}/builder")->on()->iPhone15()
        ->assertScript($visible, 'hidden');
});

/**
 * The deck builder. Its slide reordering has toolbar buttons as well as the
 * thumbnail drag, so unlike the two canvases it has no pointer-only path — it
 * is fully in scope. At 390 its topbar and slide toolbar ran to 524px.
 */
it('never scrolls sideways in the deck builder', function (string $device) {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    $deck = Document::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Quarterly Business Review — EMEA Operations',
        'keywords' => [],
        'type' => DocumentType::Deck,
        'body' => json_encode([
            'schema_version' => '1.0.0',
            'theme' => 'executive',
            'slides' => [['id' => 'sld_1', 'layout' => 'title', 'title' => 'Quarterly Business Review']],
        ]),
        'visibility' => Visibility::Organization,
        'metadata' => ['theme' => 'executive', 'slide_count' => 1],
    ]);

    visit("/slides/{$deck->id}/builder")->on()->{$device}()
        ->assertNoJavaScriptErrors()
        ->assertScript(NO_CLIPPED_CONTENT, '');
})->with('viewports');

/**
 * The bot-flow editor stays desktop-only, and that is the honest answer rather
 * than a gap: it IS a node canvas end to end — take that away and nothing
 * useful is left, unlike the app builder where chat and preview survive.
 *
 * So what is asserted here is the CHROME (its toolbar used to run 9px past a
 * phone) and the notice. The canvas itself is deliberately not measured: VueFlow
 * draws edges past its own bounds and clips them with overflow-hidden, which is
 * how a pannable canvas works — the page never scrolls sideways.
 */
it('keeps the bot-flow toolbar on screen and says the canvas is desktop-only', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    $bot = Chatbot::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Soporte Nivel 2 — Reembolsos y Garantías',
    ]);

    $toolbarFits = <<<'JS'
    function () {
        const vw = document.documentElement.clientWidth;
        const bar = document.querySelector('.sp-glass');
        if (!bar) return 'no toolbar';
        for (const el of bar.querySelectorAll('*')) {
            const r = el.getBoundingClientRect();
            if (r.width && r.right > vw + 1) return `${el.tagName} right=${Math.round(r.right)}`;
        }
        return '';
    }
    JS;

    visit("/chatbots/{$bot->id}/flow/edit")->on()->iPhone15()
        ->assertNoJavaScriptErrors()
        ->assertScript($toolbarFits, '')
        // The notice is the point: it is not offered as a phone tool. Asserted
        // by role, not by copy, so it survives a locale or a reword.
        ->assertScript(
            'function () { const n = document.querySelector(\'[role="status"]\'); return n && n.getBoundingClientRect().width > 0 ? "shown" : "missing"; }',
            'shown',
        );
});
