<?php

namespace App\Services\Platforms\Strategies\Contracts;

// SEAM INTERFACE — declared so future API-key-authenticated platforms slot in by
// implementing this interface, with NO restructuring of the registry/spine.
// Intentionally EMPTY and UNIMPLEMENTED in the current plan (YAGNI): there is no
// API-key platform yet. Do not add a concrete implementation here.
interface ApiKeyConnect extends ConnectStrategy {}
