<?php

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * An import prompt carries the page it is reproducing — tens of KB of rendered
 * DOM and CSS — and the transcript used to render every byte, burying the
 * conversation. The content still has to BE there (it is the prompt the model
 * answered, and it reads it back from the stored message on later turns), so
 * only the presentation changes.
 */
function transcriptAppWithPayload(): string
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'payload', 'name' => 'Payload', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    $conv = BuilderConversation::create([
        'app_id' => $app->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'status' => 'active',
    ]);

    BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'user', 'status' => 'none',
        'content' => "Reconstruye esta landing lo más fiel posible.\n\n```html\n"
            .str_repeat('<section class="hero"><h1>Build your first agent</h1></section>', 60)
            ."\n```\n\nMantén el copy literal.",
    ]);

    return $app->id;
}

it('collapses an import payload instead of printing it into the transcript', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = transcriptAppWithPayload();

    $shape = <<<'JS'
    function () {
        const d = document.querySelector('details');
        if (!d) return 'no details';
        if (d.open) return 'open by default';
        // The prose around the payload must still read as prose.
        const body = document.body.innerText;
        if (!body.includes('Reconstruye esta landing')) return 'prose missing';
        if (!body.includes('Mantén el copy literal')) return 'trailing prose missing';
        // Collapsed: the markup must not be sitting in the visible text.
        if (body.includes('Build your first agent')) return 'payload still printed';
        // …but it must still be in the DOM, because it is the prompt.
        return d.querySelector('pre')?.textContent?.includes('Build your first agent')
            ? 'collapsed' : 'payload lost';
    }
    JS;

    visit("/apps/{$appId}/builder")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertScript($shape, 'collapsed');
});

it('labels the collapsed payload with its language and size', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $appId = transcriptAppWithPayload();

    $label = <<<'JS'
    function () { const s = document.querySelector('details summary'); return s ? s.textContent.trim() : 'none'; }
    JS;

    // 60 copies of a ~60-byte section: a few KB, named so the reader can decide.
    visit("/apps/{$appId}/builder")->on()->macbookAir()
        ->assertScript($label, 'HTML · 4 KB');
});

it('leaves a short snippet inline', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    $app = App::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id, 'slug' => 'snippet', 'name' => 'Snippet', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);
    $conv = BuilderConversation::create([
        'app_id' => $app->id, 'user_id' => $user->id, 'organization_id' => $org->id, 'status' => 'active',
    ]);
    BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'user', 'status' => 'none',
        'content' => "Usa este color:\n```css\n.hero{color:#0096FF}\n```",
    ]);

    // Hiding a two-line snippet behind a toggle would be worse than showing it.
    visit("/apps/{$app->id}/builder")->on()->macbookAir()
        ->assertScript('function () { return document.querySelector("details") ? "collapsed" : "inline"; }', 'inline')
        ->assertSee('.hero{color:#0096FF}');
});
