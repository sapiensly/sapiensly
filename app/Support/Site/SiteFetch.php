<?php

namespace App\Support\Site;

use App\Services\Site\SiteProfileFetcher;

/**
 * The outcome of reading a website: the profile when there is one, and always a
 * reason.
 *
 * {@see SiteProfileFetcher} used to answer null for every
 * way a fetch can fail, which left the UI with one message — "the site could not
 * be read" — for four different situations the user would fix four different
 * ways. A host that does not resolve, a page behind a login, a URL pointing at
 * a PDF and a page with nothing on it are not the same problem, and telling
 * someone which one they have is most of the help this feature can offer.
 */
final class SiteFetch
{
    /** The page was read and yielded something worth using. */
    public const OK = 'ok';

    /** Nothing was typed. */
    public const NO_URL = 'no_url';

    /** The typed URL is not a fetchable http(s) address. */
    public const INVALID_URL = 'invalid_url';

    /** The host never answered, refused us, or answered with an error status. */
    public const UNREACHABLE = 'unreachable';

    /** It answered, but with a PDF/image/JSON — not a page we can read. */
    public const NOT_HTML = 'not_html';

    /** It is a page, and there is nothing on it we can use. */
    public const EMPTY_PAGE = 'empty';

    private function __construct(
        public readonly ?SiteProfile $profile,
        public readonly string $reason,
    ) {}

    public static function ok(SiteProfile $profile): self
    {
        return new self($profile, self::OK);
    }

    public static function failed(string $reason): self
    {
        return new self(null, $reason);
    }

    public function successful(): bool
    {
        return $this->profile !== null;
    }
}
