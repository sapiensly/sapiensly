<?php

namespace App\Enums;

/**
 * What a public bot is allowed to offer when a visitor wants a person.
 *
 * Ordered by how much the product can actually deliver. `Live` was withheld
 * until there was a way for a human's words to reach the visitor, because a bot
 * told "someone is available" without that is the same empty promise this whole
 * line of work removes — it now exists, and it is gated on measured presence
 * rather than on hope or on a configured schedule.
 */
enum HandoffMode: string
{
    /** Someone is watching the inbox right now and can join this conversation. */
    case Live = 'live';

    /** The organization named a channel where people actually answer. */
    case Redirect = 'redirect';

    /** Nobody to point at, so the honest offer is to take their details. */
    case Capture = 'capture';
}
