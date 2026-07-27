<?php

namespace App\Ingest\Message;

/**
 * What a connector emits. A shallow data-only sum type — the ONLY inheritance
 * permitted in this layer (plan §4: zero base classes for connectors
 * themselves). A connector is a generator of these and nothing else; it never
 * writes, never decides, never knows what happens next.
 */
abstract readonly class Message {}
