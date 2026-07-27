<?php

namespace App\Enums;

/**
 * What a public bot is allowed to offer when a visitor wants a person.
 *
 * There is deliberately no `Live` case yet. Live takeover needs somewhere for
 * the human's words to come back through, and until that exists a bot told
 * "someone is available" would be making the same empty promise this whole
 * change removes. The case arrives with the channel that makes it true.
 */
enum HandoffMode: string
{
    /** The organization named a channel where people actually answer. */
    case Redirect = 'redirect';

    /** Nobody to point at, so the honest offer is to take their details. */
    case Capture = 'capture';
}
