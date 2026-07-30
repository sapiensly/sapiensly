<?php

namespace App\Services\Import;

use App\Services\Security\Ssrf\SafeHttpClient;
use App\Services\Security\Ssrf\SsrfBlockedException;
use RuntimeException;

/**
 * Downloads a spreadsheet the user (or a model) named by URL.
 *
 * Two things make this more than an HTTP GET:
 *
 *  1. A Google Sheets link points at an EDITOR, not a file. Fetching it returns
 *     an HTML page, which then imports as one column of markup. The share URL
 *     is rewritten to the sheet's CSV export before anything is fetched.
 *  2. The URL is attacker-influenced — a builder turn can be talked into
 *     importing "http://169.254.169.254/latest/meta-data/". Every fetch goes
 *     through {@see SafeHttpClient}, which resolves and clears the address and
 *     pins the connection to it, so a hostname cannot rebind to a private
 *     range between the check and the request.
 */
class RemoteSheetFetcher
{
    /** Downloads above this are refused rather than streamed into memory. */
    private const MAX_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private readonly SafeHttpClient $http,
        private readonly SpreadsheetReader $reader,
    ) {}

    /**
     * @throws RuntimeException when the URL cannot be fetched or read
     */
    public function fetch(string $url): SheetData
    {
        return $this->reader->readBytes($this->fetchRaw($url), $url);
    }

    /**
     * The downloaded BYTES, before any parsing — what a queued import needs to
     * park on disk for the job to read later.
     *
     * @throws RuntimeException when the URL cannot be fetched
     */
    public function fetchRaw(string $url): string
    {
        $url = trim($url);
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new RuntimeException('The link must start with http:// or https://.');
        }

        $url = self::normalizeGoogleSheets($url);

        try {
            $response = $this->http->request('GET', $url, ['timeout' => 20]);
        } catch (SsrfBlockedException) {
            throw new RuntimeException('That address cannot be reached from here.');
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not download that file: '.$e->getMessage());
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'The link returned HTTP '.$response->status().'. If the sheet is private, publish it or share it with anyone-with-the-link.',
            );
        }

        $body = $response->body();
        if ($body === '') {
            throw new RuntimeException('The link returned an empty file.');
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('That file is too large to import.');
        }

        // A private Sheet answers a redirect to the sign-in page with HTTP 200
        // and an HTML body. Importing that would produce a column of markup and
        // look like a parsing bug rather than a permissions problem.
        if (self::looksLikeHtml($body)) {
            throw new RuntimeException(
                'That link returned a web page, not a file. If it is a Google Sheet, share it with anyone-with-the-link (or use File > Share > Publish to the web).',
            );
        }

        return $body;
    }

    /**
     * Turn a Google Sheets share/edit URL into its CSV export, preserving the
     * selected tab (`gid`) so a link to the second sheet imports the second
     * sheet. Any other URL is returned untouched.
     */
    public static function normalizeGoogleSheets(string $url): string
    {
        if (preg_match('#^https://docs\.google\.com/spreadsheets/d/([a-zA-Z0-9_-]+)#', $url, $m) !== 1) {
            return $url;
        }

        // Already an export link — leave the caller's own parameters alone.
        if (str_contains($url, '/export')) {
            return $url;
        }

        $gid = preg_match('/[#&?]gid=(\d+)/', $url, $g) === 1 ? $g[1] : '0';

        return "https://docs.google.com/spreadsheets/d/{$m[1]}/export?format=csv&gid={$gid}";
    }

    private static function looksLikeHtml(string $body): bool
    {
        $head = strtolower(substr(ltrim($body), 0, 200));

        return str_starts_with($head, '<!doctype html')
            || str_starts_with($head, '<html')
            || str_contains($head, '<head>');
    }
}
