<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\User;
use App\Services\Print\HeadlessPdf;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A printable copy of what is on the screen.
 *
 * An app could show somebody a work order, a quote or a delivery note and had
 * no way to hand it to them: the person who needed the paper was the customer,
 * who does not have the app.
 *
 * Two routes, and the split is the whole design. `download` is the ordinary
 * authenticated one a button reaches. `render` is signed, short-lived and
 * session-less, because headless Chrome arrives with no cookies — the signature
 * carries the tenant scope, and only this server can mint one.
 *
 * `render` deliberately delegates to {@see AppRuntimeController} rather than
 * building the props again. A print-only page built beside the real one is a
 * second thing to keep true, and the day they disagree is the day somebody
 * sends a customer a PDF of something that is not on their screen.
 */
class AppPrintController extends Controller
{
    /**
     * Paper, by the name it is SOLD under.
     *
     * A label size out by two millimetres is a wasted roll, and nobody buys
     * "3.94 by 5.91 inches" — they buy 4×6. Named sizes also mean the manifest
     * cannot carry a number somebody mistyped.
     *
     * @var array<string, array{paper: array{0: float, 1: float, 2: string}, margin: float}>
     */
    private const PAPER = [
        'a4' => ['paper' => [8.27, 11.69, 'in'], 'margin' => 0.4],
        'letter' => ['paper' => [8.5, 11.0, 'in'], 'margin' => 0.4],
        // Labels print edge to edge: a margin on a 2-inch label is most of
        // the label.
        'label_4x6' => ['paper' => [4.0, 6.0, 'in'], 'margin' => 0.0],
        'label_2x1' => ['paper' => [2.0, 1.0, 'in'], 'margin' => 0.0],
        'label_dymo' => ['paper' => [2.44, 1.14, 'in'], 'margin' => 0.0],
    ];

    public function __construct(private readonly HeadlessPdf $pdf) {}

    /**
     * The PDF itself. Everything on the query string rides along to the render,
     * so `?id=rec_…` prints THAT record's page and a filtered list prints the
     * rows the reader was looking at.
     */
    public function download(Request $request, string $appSlug, string $pageSlug): Response
    {
        $user = $request->user();

        $app = App::query()->forAccountContext($user)->where('slug', $appSlug)->first();
        if ($app === null) {
            throw new NotFoundHttpException("App '{$appSlug}' not found.");
        }

        $params = collect($request->query())
            ->filter(fn ($v): bool => is_string($v) || is_numeric($v))
            ->map(fn ($v): string => (string) $v)
            ->all();

        $url = URL::temporarySignedRoute('apps.runtime.print', now()->addMinutes(5), [
            'app_slug' => $app->slug,
            'page_slug' => $pageSlug,
            'uid' => $user->id,
            'org' => $user->organization_id,
            ...$params,
        ]);

        $paper = self::PAPER[(string) $request->query('paper', 'a4')] ?? self::PAPER['a4'];

        $path = $this->pdf->render(
            $url,
            'window.__spPrintReady === true',
            $paper['paper'],
            margin: $paper['margin'],
        );

        if ($path === null) {
            // Said plainly rather than as a broken download: rendering needs a
            // browser on the server, and "the file is empty" would send
            // somebody looking in entirely the wrong place.
            abort(503, 'The PDF could not be generated. Headless rendering is unavailable on this server.');
        }

        return response()
            ->download($path, Str::slug($app->name.'-'.$pageSlug).'.pdf')
            ->deleteFileAfterSend(true);
    }

    /**
     * The page headless Chrome loads. Signed-URL only, no session: the tenant
     * scope comes from the signature's own parameters, exactly as the landing
     * and deck renderers do it.
     */
    public function render(Request $request, string $appSlug, string $pageSlug, AppRuntimeController $runtime): mixed
    {
        $owner = User::find((int) $request->query('uid'));
        abort_if($owner === null, 404);

        $org = $request->query('org') !== null ? (string) $request->query('org') : null;
        abort_if($org !== ($owner->organization_id ?: null), 404);

        $ctx = app(TenantContext::class);
        $previousOrg = $ctx->organizationId();
        $previousUid = $ctx->userId();
        $ctx->set($owner->organization_id, $owner->id);

        // The runtime controller reads the actor off the request. Setting it
        // here is what lets the SAME code path serve this render, so what gets
        // printed is what the owner would see.
        Auth::setUser($owner);

        try {
            $response = $runtime($request, $appSlug, $pageSlug);

            // The one thing that differs from the on-screen page: no menu, no
            // header, no footer. They are navigation, and paper has nowhere to
            // navigate to.
            return $response->with('printing', true);
        } finally {
            $ctx->set($previousOrg, $previousUid);
        }
    }
}
