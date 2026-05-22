- [ ] **#CCG-1** · P2 — Uncached Square `/v2/locations` call on public booking config endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:288–289 (resolvePrimaryLocation), called from config() at line 80
    - **Affects:** Every visitor opening a booking section on a public mini-site; the same Square locations payload is re-fetched on every request with no cache layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `resolvePrimaryLocation()` result in `CacheLockService::rememberLocked` with a key from `CacheKeyGenerator` (e.g. `square_locations_{$professionalId}`) and a 5–10 minute TTL.
        - Bust the key in any Square catalog webhook handler that touches location data (or on explicit dashboard reconnect), so a location rename propagates within the TTL window.
    - **Technical:** `resolvePrimaryLocation()` issues `$this->squareApiClient->request($professional, 'GET', '/v2/locations')` synchronously inside the `config()` method. The result — a single active location's id/name/currency — is identical for every concurrent visitor to the same professional's booking section. Square locations change only on rare administrative edits, making this a textbook `rememberLocked` candidate. The canonical fix is a short-TTL cache + push-invalidate on the relevant Square webhook.
    - **Plain English:** Every time a visitor opens the booking section on a Partna mini-site, the server calls Square to ask "what locations does this professional have?" The answer almost never changes, but we make the call anyway — on every single visitor. That's like calling a store to ask their address every time a customer walks in, instead of writing it on a sticky note and reusing it for a few minutes.
    - **Evidence:**
        ```php
        // PublicBookingController.php:80 — config() calls resolvePrimaryLocation
        $location = $this->resolvePrimaryLocation($professional);
        
        // PublicBookingController.php:288–289 — resolvePrimaryLocation() hits Square API
        $response = $this->squareApiClient->request($professional, 'GET', '/v2/locations');
        $locations = is_array($response['locations'] ?? null) ? $response['locations'] : [];
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCG-2** · P2 — Uncached Square `fetchAppointmentServiceVariations` call on public booking services endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:103–104 (services method)
    - **Affects:** Every visitor loading the services list in a public booking section; the same service catalog is re-fetched from Square on every request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `fetchAppointmentServiceVariations` call in `CacheLockService::rememberLocked` with a per-professional key (`square_services_{$professionalId}`) and a 5-minute TTL.
        - Invalidate the key from `SquareCatalogWebhookController` (which already receives `catalog.version.updated` events) so a service edit in Square surfaces within seconds rather than waiting for TTL expiry.
    - **Technical:** The `services()` method calls `$this->squareApiClient->fetchAppointmentServiceVariations($professional, null)` with no cache wrapper. This fetches the full bookable-service catalog from the Square API synchronously on every request. The service catalog is identical for all concurrent visitors to the same professional and only changes when the professional edits services in Square. The `SquareCatalogWebhookController` already handles `catalog.version.updated` webhooks — adding cache invalidation there gives push-based freshness without any new webhook subscriptions.
    - **Plain English:** Every visitor browsing a professional's bookable services triggers a live call to Square's servers to ask "what services do you offer?" The answer is the same for everyone looking at the same professional, and it only changes when the professional edits their Square catalog. We should cache the answer for a few minutes and update it only when Square tells us something changed — like keeping a menu on the counter instead of calling the kitchen for every customer.
    - **Evidence:**
        ```php
        // PublicBookingController.php:103–104 — uncached Square API fetch
        $fetched = $this->squareApiClient->fetchAppointmentServiceVariations($professional, null);
        $rows = is_array($fetched['services'] ?? null) ? $fetched['services'] : [];
        ```
    - `[DRAFT, confidence: 0.85]`
