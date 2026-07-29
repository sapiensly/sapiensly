<?php

namespace App\Services\Branding;

use RuntimeException;

/**
 * A remote brand image that could not be adopted, with a message written for
 * whoever asked for it — the host went away, it served something that is not an
 * importable image, or it is too big.
 *
 * Never fatal to the surrounding import: one logo that would not copy must not
 * throw away the colours and the typeface that came from the same reading.
 */
class BrandAssetImportFailed extends RuntimeException {}
