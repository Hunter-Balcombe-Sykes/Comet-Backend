<?php

namespace App\Ingest\Runtime;

use App\Ingest\Manifest\Manifest;
use App\Ingest\Message\Message;

/**
 * The entire connector contract: two methods, no base class, no lifecycle
 * hooks, no DSL (plan §4). A connector is straight-line PHP that describes
 * itself and yields Messages; every side effect goes through the injected
 * Io, which is what makes connectors replayable from a recorded effect log
 * and testable with no network at all.
 */
interface Connector
{
    public static function manifest(): Manifest;

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable;
}
