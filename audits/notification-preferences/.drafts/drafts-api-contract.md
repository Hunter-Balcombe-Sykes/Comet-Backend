- [ ] **#API-1** · P2 — Notification listing returns raw stdClass rows, bypassing API Resource layer entirely
    - **Where:** app/Services/Notifications/NotificationListingService.php:103-126 (buildIndexPayload)
    - **Affects:** All callers of `GET /me/notifications` (Professional dashboard bell) and the Staff-on-behalf-of endpoint — both receive raw database column names directly in JSON responses.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `NotificationResource` class in `app/Http/Resources/` that explicitly lists allowed fields.
        - Wrap every row in `buildIndexPayload` through `NotificationResource::make($row)` before returning.
        - If Staff and Professional surfaces should show different fields, create `StaffNotificationResource` vs `ProfessionalNotificationResource` and route which one the service uses.
    - **Technical:** The `buildIndexPayload` method selects columns via `DB::table('notifications.notifications as n')->leftJoin(...)->get([...])` and returns the raw collection via `$rows->values()->all()`. No Eloquent model serialisation, no `$hidden`/`$appends` protection, no Resource class gate. If a developer adds a column like `admin_notes` to the select list, it immediately appears in the Professional API. The Partna architecture mandates that all API responses go through Resource classes — this service bypasses that contract entirely. The Notification model IS an Eloquent model (`app/Models/Core/Notifications/Notification.php`), so there's a model to anchor a Resource class to; the service just doesn't use it.
    - **Plain English:** Think of it like a restaurant kitchen that sends dishes out through the pass (Resource classes) — every plate gets checked before it reaches the customer. The notification list bypasses the pass entirely, handing raw ingredients straight to the table. If a chef later adds a new ingredient to the prep list (a new database column), it lands on the customer's plate with no one checking whether it belongs there. The fix is to route this through the same pass every other dish uses.
    - **Evidence:**
        ```php
        // NotificationListingService.php: buildIndexPayload()
        $rows = $listQuery
            ->orderByDesc('n.created_at')
            ->limit($limit + 1)
            ->get([
                'n.id',
                'n.professional_id',
                'n.type',
                'n.title',
                'n.body',
                'n.cta_url',
                'n.primary_action_label',
                'n.secondary_action_label',
                'n.secondary_action_url',
                'n.severity',
                'n.starts_at',
                'n.ends_at',
                'n.created_at',
                'r.read_at',
                'r.dismissed_at',
            ]);

        // ... map + slice ...

        return [
            'unread_count' => $unreadCount,
            'has_more' => $hasMore,
            'notifications' => $rows->values()->all(),  // raw stdClass objects
        ];
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#API-2** · P3 — Notification listing pagination shape differs from all other list endpoints
    - **Where:** app/Services/Notifications/NotificationListingService.php:89-102 and app/Http/Controllers/Api/Professional/Notifications/NotificationController.php:27-31
    - **Affects:** Frontend developers building notification UIs — they need different pagination logic for notifications (`limit` + `has_more` boolean) vs every other list endpoint (`page` + `per_page` with `meta.current_page`/`meta.last_page`/`links.next`).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Decide on one pagination contract — either migrate notifications to `paginate()` style, or document the divergence as intentional with a clear reason (e.g. real-time polling doesn't benefit from total-page metadata).
        - If `limit`+`has_more` is kept, add a `next_cursor` or `next_page` token so clients can request subsequent pages deterministically.
        - Ensure the response envelope includes enough metadata for clients to know there are more results without counting returned items.
    - **Technical:** `NotificationController::index()` accepts `?limit=` (default 50) and the service returns `{unread_count, has_more, notifications: [...]}`. Contrast with `ProfessionalEmailSubscriptionController::index()` which uses `$query->paginate($perPage)` and returns the standard paginated shape with `meta` and `links`. Clients must maintain two code paths — one that reads `has_more` and increments a local offset, and another that reads `meta.current_page` and follows `links.next`. The notification approach is closer to cursor-based pagination but omits the cursor token, so there's no way to resume from where the last page left off if new notifications arrive between requests.
    - **Plain English:** Most of the API works like a book with numbered pages — you ask for page 3, you get page 3. The notification list works like a scroll — you get 50 items and a flag saying "there's more." Both are valid, but the client has to build two different reading mechanisms. It's like having a library where half the books use page numbers and half use "keep scrolling." The fix is to either number all the pages or give the scroll a bookmark so the client knows where to resume.
    - **Evidence:**
        ```php
        // NotificationController.php:27-31 — limit-based, no page parameter
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));
        $includeDismissed = filter_var($request->query('include_dismissed', false), FILTER_VALIDATE_BOOLEAN);
        return $this->success($this->listing->index($pro->id, $limit, $includeDismissed));

        // vs ProfessionalEmailSubscriptionController.php — page-based paginate()
        $page = $query->paginate($perPage)->appends($request->query());
        return $this->success($this->paginatedResponse($page, 'subscriptions', [...]));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#API-3** · P3 — DataExportPayloadBuilder leaks ip_hash and user_agent on lead_submissions but redacts them on enquiries
    - **Where:** app/Services/Professional/DataExport/DataExportPayloadBuilder.php:113-117 (enquiries, redacted) vs :158-164 (bookings→lead_submissions, not redacted)
    - **Affects:** Professionals who request a GDPR data export — their export zip contains IP hash and user-agent strings for lead submissions but not for enquiries, creating an inconsistency in what technical metadata is disclosed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Redact `ip_hash` and `user_agent` from lead_submissions in the export payload, matching the enquiries approach.
        - OR explicitly document why leads carry technical metadata but enquiries don't (e.g. spam analysis), and apply the same rule consistently.
        - Also remove `ip_hash` and `user_agent` from the lead_submissions query if the `ExportCustomerDataJob` pattern is the intended one (that job already strips them).
    - **Technical:** The `enquiries()` method in `DataExportPayloadBuilder` uses `->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])` — deliberately omitting `ip_hash` and `user_agent`. The `bookings()` method, which includes lead_submissions, uses `->get()->map(fn ($r) => (array) $r)` — selecting all columns including `ip_hash` and `user_agent`. The `ExportCustomerDataJob::gatherExportData()` (Shopify GDPR path) maps lead_submissions to an array but also selects all columns, creating the same inconsistency. The platform's stance on enquiries (strip technical fingerprints) should apply uniformly to lead submissions — both are user-submitted forms tracked for abuse prevention, not data the professional needs in their export.
    - **Plain English:** When a business owner downloads their data, the "contact form messages" section thoughtfully hides technical tracking info (IP address hash, browser type). But the "lead form submissions" section includes that same tracking info. It's like redacting a phone number on page one of a report but printing it in full on page three. The fix is to apply the same redaction rule everywhere, so the export is consistent regardless of which form the customer filled out.
    - **Evidence:**
        ```php
        // DataExportPayloadBuilder.php:113-117 — enquiries ARE redacted
        return DB::connection('pgsql')
            ->table('site.enquiries')
            ->select(['id', 'name', 'email', 'phone', 'subject', 'message', 'created_at'])
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        // DataExportPayloadBuilder.php:161-165 — lead_submissions are NOT redacted
        $leads = DB::connection('pgsql')
            ->table('analytics.lead_submissions')
            ->where('professional_id', $professionalId)
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#API-4** · P3 — No audience-specific Resource class exists for EmailSubscription; Professional and Staff endpoints share raw model serialisation
    - **Where:** app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php:51-52 and app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:46-47
    - **Affects:** Future development — if a field is added to `EmailSubscription` that should be Staff-only (e.g. `admin_notes`, `flagged_reason`), the `$hidden` attribute on the Eloquent model would either hide it from both audiences or show it to both. There's no per-audience filter.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `ProfessionalEmailSubscriptionResource` and `StaffEmailSubscriptionResource` classes.
        - Have the Professional Resource expose only the brand-owner-relevant fields (`email`, `full_name`, `status`, `subscribed_at`, `list_key`).
        - Have the Staff Resource expose the same fields — they're currently identical in scope, but the separation makes future Staff-only additions safe by default.
        - Register both in `AppServiceProvider` and route the appropriate Resource in each controller.
    - **Technical:** Both controllers call `EmailSubscription::query()->paginate()` and pass the paginator through `$this->paginatedResponse()`. The items in the paginator are Eloquent models serialised via `toArray()`, which respects `$hidden` and `$casts`. Currently `$hidden = ['unsubscribe_token', 'consent_ip_hash', 'consent_user_agent']` — these are correctly hidden from both audiences. But this is an all-or-nothing gate. The Partna architecture expects per-audience Resource classes for any model served to more than one API surface. Without them, the next developer who adds a Staff-internal field to the model has no obvious place to say "this is Staff-only" and will likely either add it to `$hidden` (breaking the Staff endpoint) or leave it exposed (leaking it to Professionals).
    - **Plain English:** Right now, the subscriber list looks the same whether the brand owner views it or a Partna staff member views it. That's fine today because there's nothing sensitive that differs between them. But the architecture is designed for each audience to have its own "lens." Without that lens in place, the next person who adds a staff-only note field has nowhere to put it — they'll either hide it from everyone (breaking the staff view) or show it to everyone (leaking internal notes to brand owners). Setting up the lenses now, even if they show the same fields, prevents that future mistake.
    - **Evidence:**
        ```php
        // ProfessionalEmailSubscriptionController.php:51-52
        $page = $query->paginate($perPage)->appends($request->query());
        return $this->success($this->paginatedResponse($page, 'subscriptions', [...]));

        // StaffEmailSubscriberController.php:46-47 — identical pattern, no Resource
        $page = $query->paginate($perPage)->appends($request->query());
        return $this->success($this->paginatedResponse($page, 'subscriptions', [...]));
        ```
    - `[DRAFT, confidence: 0.75]`
