<?php

namespace App\Services\Print;

use Illuminate\Support\Str;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * A page, rendered by a real browser, as a PDF.
 *
 * Extracted when the SECOND thing needed it. The deck export had been doing
 * this alone for months, and a record's printable copy needs exactly the same
 * six decisions — the signed URL, the readiness signal, the paper, the
 * margins, the node binary, and what to do when Chromium is not there.
 *
 * The URL is always a signed, short-lived, session-less route: headless Chrome
 * arrives with no cookies, so the signature IS the authorization, and only
 * server code that already resolved a visible record can mint one.
 *
 * It renders the SAME page a person sees rather than a print-only template
 * built beside it. A second template is a second thing to keep true, and the
 * day they disagree is the day somebody sends a customer a PDF of something
 * that is not on their screen.
 */
class HeadlessPdf
{
    /**
     * Render a signed URL to a PDF on local disk.
     *
     * @param  string  $readySignal  A JS expression the page makes true once it
     *                               has mounted and its fonts have loaded.
     *                               Without one, Chromium prints a half-drawn page.
     * @param  array{0: float, 1: float, 2: string}  $paper  width, height, unit
     * @return string|null Absolute path to the file, or null when it could not
     *                     be rendered at all. The caller deletes it after send.
     */
    public function render(
        string $url,
        string $readySignal,
        array $paper = [8.27, 11.69, 'in'],
        int $timeoutSeconds = 90,
        float $margin = 0.4,
    ): ?string {
        // Browsershot (Chromium launch + hydrate + print) routinely outruns a
        // web request's max_execution_time, which would kill the request
        // mid-render. Extend it to cover the work — but only when a finite
        // limit is set (never on CLI or a queue, where it is 0).
        $budget = $timeoutSeconds + 30;
        $current = (int) ini_get('max_execution_time');
        if ($current !== 0 && $current < $budget) {
            @set_time_limit($budget);
        }

        $path = sys_get_temp_dir().'/sp-'.Str::random(12).'.pdf';

        try {
            $shot = Browsershot::url($url)
                ->windowSize(1280, 1600)
                ->waitForFunction($readySignal)
                ->timeout($timeoutSeconds)
                // Without it, every card, badge and chart on the page prints
                // as white-on-white: Chromium drops backgrounds by default.
                ->showBackground()
                ->margins($margin, $margin, $margin, $margin, 'in')
                ->paperSize($paper[0], $paper[1], $paper[2])
                ->noSandbox()
                ->setNodeModulePath(base_path('node_modules'));

            if (is_string($node = config('services.node.binary')) && $node !== 'node') {
                $shot->setNodeBinary($node);
            }

            $shot->savePdf($path);

            return is_file($path) ? $path : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
