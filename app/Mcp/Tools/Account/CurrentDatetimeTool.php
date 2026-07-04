<?php

namespace App\Mcp\Tools\Account;

use App\Mcp\Tools\SapiensTool;
use App\Support\CurrentDateTime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('The current date and time in UTC — {utc (ISO-8601), date, time, day_of_week, unix}. You have no internal clock: call this for ANY time-relative reasoning (today, "last N days", ages, deadlines, scheduling, date filters) instead of guessing or assuming the date.')]
class CurrentDatetimeTool extends SapiensTool
{
    // No ABILITY: the clock is available to any valid token.

    public function handle(Request $request): Response
    {
        return Response::json(CurrentDateTime::payload());
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
