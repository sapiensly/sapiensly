<?php

namespace App\Support\Landing;

/**
 * Recovers the MOTION of a bundled design — the part a screenshot cannot hold.
 *
 * The importer renders the page and hands the model the resulting DOM, with
 * reduced-motion forced and every scroll-reveal already resolved so the whole
 * page is visible at once. That is the right frame to LOOK at and the wrong one
 * to learn movement from: it is a photograph of something that moves.
 *
 * In a React export the movement isn't in the stylesheet either — measured on a
 * real one, the design system held 7 @keyframes while the components carried 28
 * `transition:` declarations, 11 `onMouseEnter` handlers, 3 IntersectionObservers
 * and a timer. None of that reaches the DOM. The rebuild came back with 3 hovers
 * and 2 transitions, not because the model was careless but because it was
 * guessing from a still.
 *
 * The sources are already in hand (BundledDesign unpacks the same manifest), so
 * this reads them and states the motion as facts the model can act on:
 *
 *  - hover handlers are property→value pairs (`style.color = 'var(--t-text)'`),
 *    which is a `:hover` rule written in JavaScript — directly portable;
 *  - an IntersectionObserver that sets "shown" once IS `data-sp-reveal`;
 *  - one that staggers children on a timer IS `data-sp-sequence`;
 *  - anything else on a clock (a replay button, a typing caret) is honestly not
 *    portable, and saying so beats leaving a dead control on the page.
 */
final class BundledMotion
{
    /** Vendor bundles (React, ReactDOM, Babel) start around 100 KB. */
    private const MAX_AUTHORED_CHARS = 100000;

    /** Keep the brief small enough to be read rather than skimmed past. */
    private const MAX_ITEMS = 10;

    /**
     * A model-facing brief, or null when there is no bundle / nothing to say.
     */
    public static function brief(string $html): ?string
    {
        $sources = self::authoredSources($html);
        if ($sources === '') {
            return null;
        }

        $lines = [];

        if ($transitions = self::transitions($sources)) {
            $lines[] = '- Transiciones del original: '.implode(' · ', $transitions)
                .'. Aplícalas en el CSS a los mismos elementos (botones, tarjetas, enlaces de nav).';
        }

        if ($hovers = self::hoverPairs($sources)) {
            $lines[] = '- Estados HOVER (en el original son JS, pero son pares propiedad→valor: escríbelos como reglas `:hover` en custom_css, ahí SÍ se portan): '
                .implode(' · ', $hovers).'.';
        }

        if ($animations = self::animations($sources)) {
            $lines[] = '- Animaciones con nombre que el original ejecuta: '.implode(' · ', $animations)
                .'. Sus @keyframes vienen en el CSS de arriba — reúsalos.';
        }

        foreach (self::observers($sources) as $observer) {
            $lines[] = '- '.$observer;
        }

        if ($lines === []) {
            return null;
        }

        return 'MOVIMIENTO DEL ORIGINAL — extraído de sus componentes, porque el HTML de arriba es UN fotograma '
            ."(capturado con reduced-motion y los reveals ya resueltos) y no contiene nada de esto:\n"
            .implode("\n", $lines);
    }

    /**
     * The authored component sources, vendor bundles excluded.
     */
    private static function authoredSources(string $html): string
    {
        if (! BundledDesign::isBundle($html)) {
            return '';
        }

        // Located by string search, not a regex: the manifest runs to megabytes
        // and a lazy `(.*?)` over it blows PCRE's backtrack limit — preg_match
        // then returns false and the whole brief silently comes back empty.
        $payload = self::scriptPayload($html, '__bundler/manifest');
        if ($payload === null) {
            return '';
        }

        $manifest = json_decode($payload, true);
        if (! is_array($manifest)) {
            return '';
        }

        $parts = [];
        foreach ($manifest as $entry) {
            if (! is_array($entry) || ! isset($entry['data'], $entry['mime'])) {
                continue;
            }
            if (! str_contains((string) $entry['mime'], 'javascript') && ! str_contains((string) $entry['mime'], 'jsx')) {
                continue;
            }

            $raw = base64_decode((string) $entry['data'], true);
            if ($raw === false) {
                continue;
            }
            if (($entry['compressed'] ?? false) === true) {
                $raw = @gzdecode($raw);
                if ($raw === false) {
                    continue;
                }
            }
            // Vendor runtimes are an order of magnitude larger than a component
            // and carry motion that belongs to the framework, not the design.
            if (strlen($raw) > self::MAX_AUTHORED_CHARS) {
                continue;
            }

            $parts[] = $raw;
        }

        return implode("\n", $parts);
    }

    /**
     * The body of a <script type="…"> block, found without a regex so a
     * multi-megabyte payload can't defeat the match.
     */
    private static function scriptPayload(string $html, string $type): ?string
    {
        // Scan actual <script> TAGS. Searching for the type attribute alone
        // finds the loader's own code first — it mentions the selector as a
        // string — and returns 14 KB of bootstrap instead of the manifest.
        $needle = 'type="'.$type.'"';
        $offset = 0;

        while (($tag = stripos($html, '<script', $offset)) !== false) {
            $open = strpos($html, '>', $tag);
            if ($open === false) {
                return null;
            }

            if (stripos(substr($html, $tag, $open - $tag), $needle) !== false) {
                $close = stripos($html, '</script>', $open);
                if ($close === false) {
                    return null;
                }

                $payload = trim(substr($html, $open + 1, $close - $open - 1));

                return $payload === '' ? null : $payload;
            }

            $offset = $open + 1;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function transitions(string $sources): array
    {
        preg_match_all('/transition:\s*[\'"`]?([^\'"`;}\n]+)/i', $sources, $m);

        $found = [];
        foreach ($m[1] ?? [] as $value) {
            $value = trim($value);
            // Template interpolation (`${delay}`) is a value we can't resolve,
            // and an unbalanced paren means the value was cut mid-function.
            if ($value === '' || str_contains($value, '${')) {
                continue;
            }
            if (substr_count($value, '(') !== substr_count($value, ')')) {
                continue;
            }
            $found[$value] = true;
        }

        return array_slice(array_keys($found), 0, self::MAX_ITEMS);
    }

    /**
     * Hover handlers reduced to the CSS they are really writing.
     *
     * @return list<string>
     */
    private static function hoverPairs(string $sources): array
    {
        preg_match_all('/onMouseEnter=\{.*?\}/s', $sources, $handlers);

        $pairs = [];
        foreach ($handlers[0] ?? [] as $handler) {
            preg_match_all('/style\.(\w+)\s*=\s*[\'"]([^\'"]+)[\'"]/', $handler, $sets, PREG_SET_ORDER);
            foreach ($sets as $set) {
                // camelCase is the DOM spelling; CSS wants the dashed one.
                $property = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $set[1]));
                $pairs[$property.' → '.$set[2]] = true;
            }
        }

        return array_slice(array_keys($pairs), 0, self::MAX_ITEMS);
    }

    /**
     * @return list<string>
     */
    private static function animations(string $sources): array
    {
        preg_match_all('/animation:\s*[\'"`]?([a-zA-Z][^\'"`,;}\n]*)/i', $sources, $m);

        $found = [];
        foreach ($m[1] ?? [] as $value) {
            $value = trim($value);
            if ($value === '' || str_contains($value, '${') || ! str_starts_with($value, 'sp-')) {
                continue;
            }
            $found[$value] = true;
        }

        return array_slice(array_keys($found), 0, self::MAX_ITEMS);
    }

    /**
     * Classify each IntersectionObserver by what it actually drives, and say
     * which platform hook replaces it — or that nothing does.
     *
     * @return list<string>
     */
    private static function observers(string $sources): array
    {
        $out = [];

        foreach (self::observerContexts($sources) as $context) {
            // A timer inside the callback staggers children in one by one.
            if (preg_match('/setTimeout|setInterval/', $context) === 1) {
                $out['sequence'] = 'Aparición ESCALONADA de los hijos de un contenedor al entrar en pantalla (el original lo hace con temporizadores): usa `data-sp-sequence="<ms>"` en ese contenedor.';

                continue;
            }
            // A reveal disconnects INSIDE its own callback (fire once). A
            // toggle keeps listening and only disconnects in the effect's
            // cleanup — everything after `return () =>` is teardown, not
            // behaviour, so the callback is what decides which this is.
            $callback = explode('return () =>', $context)[0];
            if (preg_match('/isIntersecting/', $callback) === 1 && preg_match('/disconnect\(\)/', $callback) === 1) {
                $out['reveal'] = 'Reveal al hacer scroll, una sola vez por elemento: usa `data-sp-reveal` en cada sección (y `data-sp-reveal-delay` para escalonar).';

                continue;
            }
            // Anything else is state the page keeps — a sticky bar, a nav that
            // changes as you pass a section. There is no JS on the public page.
            $out['state'] = 'El original cambia de ESTADO al hacer scroll (p. ej. una barra/CTA que aparece al pasar el hero). Eso necesita JS y no se porta: no lo simules con un control muerto — o lo dejas fijo, o lo omites, y lo dices en tu resumen.';
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private static function observerContexts(string $sources): array
    {
        $contexts = [];
        $offset = 0;

        while (($at = strpos($sources, 'IntersectionObserver', $offset)) !== false) {
            $contexts[] = substr($sources, $at, 400);
            $offset = $at + 20;
        }

        return $contexts;
    }
}
