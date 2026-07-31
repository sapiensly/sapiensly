<?php

namespace App\Services\Manifest;

use RuntimeException;

/**
 * The scaffold could not produce a spec — the model call failed, timed out, or
 * came back with nothing usable.
 *
 * It exists so the failure has to be handled. The scaffolder used to answer an
 * empty spec instead, which every caller happily saved as an app with no
 * objects and no pages: a success response for a product that does not exist.
 */
class ScaffoldFailedException extends RuntimeException
{
    //
}
