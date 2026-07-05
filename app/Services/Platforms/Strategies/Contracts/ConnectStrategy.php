<?php

namespace App\Services\Platforms\Strategies\Contracts;

// How a platform turns raw user input (URL / handle) into the canonical stored
// selection array — including any upstream fetch. fail() with no message uses
// the descriptor's connectErrorMessage; fetch-stage failures carry their own
// message (and 404 where the platform's frozen contract says so).
interface ConnectStrategy
{
    public function resolve(string $input): ConnectResult;
}
