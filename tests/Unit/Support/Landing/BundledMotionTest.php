<?php

use App\Support\Landing\BundledMotion;

/**
 * A bundle whose component sources carry the motion — the shape a React export
 * has, and the shape the rendered DOM cannot show.
 */
function motionBundle(string $componentSource): string
{
    $manifest = json_encode([
        'uuid-authored' => [
            'mime' => 'application/javascript',
            'compressed' => true,
            'data' => base64_encode((string) gzencode($componentSource)),
        ],
        'uuid-vendor' => [
            // React/ReactDOM/Babel: framework motion, not the design's.
            'mime' => 'text/javascript',
            'compressed' => true,
            'data' => base64_encode((string) gzencode(str_repeat("transition: vendor 9s;\n", 6000))),
        ],
    ]);

    return '<!doctype html><html><body>'
        // The loader mentions the selector in its own code — the scan must not
        // stop there, which is what a plain attribute search does.
        .'<script>var sel = \'script[type="__bundler/manifest"]\'; boot(sel);</script>'
        .'<script type="__bundler/manifest">'.$manifest.'</script>'
        .'<script type="__bundler/template">'.json_encode('<html><head><style>:root{--a:#fff}</style></head><body><div id="root"></div></body></html>').'</script>'
        .'</body></html>';
}

it('returns null for something that is not a bundle', function () {
    expect(BundledMotion::brief('<!doctype html><html><body><h1>Hola</h1></body></html>'))->toBeNull();
});

it('recovers transitions and turns hover handlers into css pairs', function () {
    $brief = BundledMotion::brief(motionBundle(<<<'JS'
        const Btn = () => <a style={{transition:'all .25s var(--sp-ease)'}}
            onMouseEnter={e => { e.currentTarget.style.color = 'var(--t-text)'; e.currentTarget.style.transform = 'translateY(-2px)'; }}>Go</a>;
        JS));

    expect($brief)->toContain('all .25s var(--sp-ease)')
        // camelCase is the DOM spelling; a css rule needs the dashed one.
        ->and($brief)->toContain('transform → translateY(-2px)')
        ->and($brief)->toContain('color → var(--t-text)')
        ->and($brief)->toContain(':hover');
});

it('keeps a transition value whole through its commas', function () {
    // Stopping at the first comma cuts `cubic-bezier(.3` — an unusable value
    // that reads like a real one.
    $brief = BundledMotion::brief(motionBundle(
        "const X = () => <div style={{transition:'left .15s cubic-bezier(.3,.7,.4,1),width .15s'}} />;"
    ));

    expect($brief)->toContain('cubic-bezier(.3,.7,.4,1)');
});

it('maps a fire-once observer to data-sp-reveal', function () {
    $brief = BundledMotion::brief(motionBundle(<<<'JS'
        useEffect(() => {
          const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { setShown(true); io.disconnect(); } }, { threshold: 0.12 });
          io.observe(el); return () => io.disconnect();
        }, []);
        JS));

    expect($brief)->toContain('data-sp-reveal');
});

it('maps a timer-staggered observer to data-sp-sequence', function () {
    $brief = BundledMotion::brief(motionBundle(<<<'JS'
        const io = new IntersectionObserver(([e]) => {
          if (!e.isIntersecting) return;
          io.disconnect();
          TURNS.forEach((_, i) => timers.push(setTimeout(() => setN(i + 1), i * 500)));
        });
        JS));

    expect($brief)->toContain('data-sp-sequence');
});

it('calls out an ongoing scroll toggle as not portable', function () {
    // This one keeps listening; it only disconnects in the effect's CLEANUP.
    // Read naively it looks like a reveal, and the rebuild would fake it with a
    // control that never moves.
    $brief = BundledMotion::brief(motionBundle(<<<'JS'
        const io = new IntersectionObserver(([e]) => setShowCta(!e.isIntersecting), { rootMargin: '-72px 0px 0px 0px' });
        io.observe(el); return () => io.disconnect();
        JS));

    expect($brief)->toContain('no se porta')
        ->and($brief)->not->toContain('data-sp-reveal');
});

it('ignores the vendor runtime bundled alongside the components', function () {
    // React/Babel ship their own transitions; reporting them would bury the
    // design's own vocabulary in framework noise.
    $brief = BundledMotion::brief(motionBundle("const X = () => <div style={{transition:'color .2s'}} />;"));

    expect($brief)->toContain('color .2s')
        ->and($brief)->not->toContain('vendor 9s');
});

it('reports named animations but not interpolated ones', function () {
    $brief = BundledMotion::brief(motionBundle(
        "const A = () => <i style={{animation:'sp-gradient-flow 4s ease infinite'}} />;"
        .'const B = () => <i style={{animation:`sp-typing 1.2s ${i * 0.16}s`}} />;'
    ));

    expect($brief)->toContain('sp-gradient-flow 4s ease infinite')
        ->and($brief)->not->toContain('${');
});

it('says nothing when the sources carry no motion at all', function () {
    expect(BundledMotion::brief(motionBundle('const X = () => <div>plain</div>;')))->toBeNull();
});
