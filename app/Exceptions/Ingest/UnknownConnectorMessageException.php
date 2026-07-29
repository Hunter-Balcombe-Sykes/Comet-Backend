<?php

namespace App\Exceptions\Ingest;

use UnexpectedValueException;

// Thrown by RunExecutor::drain() when a connector yields a Message outside
// the sealed Record/Covered/Bookmark/Note/Deferred/Unavailable set (OBS-7).
// Previously a Log::warning breadcrumb — invisible to Nightwatch and to the
// run's own outcome, so a connector bug that started yielding garbage was
// silent. The outer catch in RunExecutor::execute() already reports this,
// marks the stream 'error', and lets the run's other streams complete.
class UnknownConnectorMessageException extends UnexpectedValueException
{
    public function __construct(string $messageClass, string $connectorClass)
    {
        parent::__construct("Connector {$connectorClass} yielded an unregistered message type {$messageClass}.");
    }
}
