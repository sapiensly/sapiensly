<?php

namespace App\Services\Capabilities\PostCall;

use App\Services\Capabilities\PostCall\Contracts\CrmConnector;
use App\Services\Capabilities\PostCall\Data\CallContext;

/**
 * Read sub-capability of #0001: fetch a call snapshot from the CRM. No side
 * effects. The read is `remote / async / may-fail`; a missing optional field
 * (e.g. no transcript) yields a partial CallContext rather than an error.
 */
class FetchCallContext
{
    public function __construct(private readonly CrmConnector $connector) {}

    public function fetch(string $callId): CallContext
    {
        return $this->connector->fetchCallContext($callId);
    }
}
