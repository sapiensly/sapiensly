<?php

namespace App\Http\Controllers;

use App\Http\Middleware\VaryOnNegotiatedLanguage;
use App\Models\App;
use App\Services\Landing\LandingChatbot;
use App\Services\Landing\LandingRuntimeProps;
use App\Services\Manifest\AppManifestService;
use App\Support\Landing\LandingLanguages;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Renders a PUBLISHED landing for anonymous visitors — the public sibling of
 * AppRuntimeController, reduced to what a marketing page needs and hardened for
 * guests. BindPublicLandingContext already resolved + gated the app and bound
 * the owner's tenant scope. Differences from the authenticated runtime:
 *
 *  - Blocks pass the PublicLandingBlocks allowlist (presentational only, no
 *    tenant data, visibility-ruled blocks dropped, action sequences stripped).
 *  - blockData ships EAGERLY as an empty map (nothing data-backed survives the
 *    filter), so the page is complete on first paint — SSR/SEO friendly, no
 *    deferred second request.
 *  - <head> metadata comes from settings.seo (title/description/og_image),
 *    falling back to the app's name/description.
 *  - No agent panel yet — the runtime agent endpoint is session-authenticated;
 *    the public conversion loop is the lead-capture slice.
 */
class PublicLandingController extends Controller
{
    public function __construct(
        private readonly AppManifestService $manifestService,
        private readonly LandingChatbot $chatbot,
    ) {}

    public function __invoke(Request $request): SymfonyResponse
    {
        /** @var App $app */
        $app = $request->attributes->get('publicLandingApp');

        $manifest = $this->manifestService->getActiveManifest($app);

        // The page ships no JavaScript, so it cannot pick its own language —
        // the decision happens here, before a byte is sent.
        $available = LandingLanguages::declared($manifest['settings'] ?? []);
        $language = LandingLanguages::resolve($request, $available);
        if ($language !== '') {
            // So the document declares what it is: screen readers announce the
            // right voice and crawlers index the right language.
            app()->setLocale($language);
        }

        // Shared with the signed headless-render route so the shot the design
        // director judges is byte-for-byte the page that ships. publicSurface
        // enables live lead_form submits + the Turnstile key.
        $page = Inertia::render('runtime/Page', [
            ...LandingRuntimeProps::build($app, $manifest, publicSurface: true, language: $language),
            // The chatbot bubble, when this landing binds one. Added HERE and not
            // inside the shared props on purpose: the headless route feeds the
            // design director, and a bubble in that shot would be judged as part
            // of the design. Absent (null) for a landing without a binding, or
            // whose bot was paused or deleted after publishing — the page still
            // renders, just without it.
            'chatbot' => $this->chatbot->forApp($app, $manifest),
            // Built here, from the URL the visitor actually used, so a custom
            // domain gets its own alternates and SSR ships them — the pass a
            // crawler reads.
            'alternates' => self::alternates($request->url(), $available),
        ]);

        if ($available === []) {
            return $page->toResponse($request);
        }

        // Vary is set by VaryOnNegotiatedLanguage on the way out: Inertia's
        // middleware replaces the header after this controller returns, so a
        // header set here would not survive.
        $request->attributes->set(VaryOnNegotiatedLanguage::ATTRIBUTE, true);

        $response = $page->toResponse($request);

        // Only a deliberate choice is remembered. Detection re-runs every visit,
        // so a change in the browser's preference is still honoured.
        if (LandingLanguages::wasExplicit($request, $available)) {
            $response->withCookie(cookie(
                LandingLanguages::COOKIE,
                $language,
                LandingLanguages::cookieLifetimeMinutes(),
                '/',
                null,
                $request->secure(),
                false,
                false,
                'Lax',
            ));
        }

        return $response;
    }

    /**
     * One URL per language. The default language is the bare URL — the one that
     * negotiates, and the one worth linking to — and the rest carry `?lang=`,
     * which is what makes a Spanish link shareable to an English browser.
     *
     * @param  list<string>  $available
     * @return list<array{lang: string, href: string}>
     */
    private static function alternates(string $url, array $available): array
    {
        $out = [];
        foreach ($available as $i => $lang) {
            $out[] = [
                'lang' => $lang,
                'href' => $i === 0 ? $url : $url.'?'.LandingLanguages::QUERY.'='.$lang,
            ];
        }

        return $out;
    }
}
