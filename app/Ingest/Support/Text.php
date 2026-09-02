<?php

namespace App\Ingest\Support;

/**
 * The bound on free prose a connector or projector stores from an upstream
 * payload — a service description, a review body, an item name.
 *
 * Lived on FreshaConnector as MAX_TEXT_LENGTH until 2026-09-02 and was read
 * from there by Booksy, Treatwell and Square, none of which have anything to
 * do with Fresha. The value is a storage posture, not a vendor's limit, so it
 * belongs beside the other cross-connector helpers rather than inside the
 * first connector that happened to need it.
 */
final class Text
{
    /**
     * #SEC-4: Fresha sets no bound on service name/description, and neither
     * does content.f_text's DDL (supabase/migrations/20260727140000_content_
     * schema.sql — "body" text, no CHECK/varchar(n)). 2000 is not invented —
     * it is the existing cap for the same shape of data one layer over:
     * Support\Html::plainText()'s default limit, applied to a vendor
     * description landing in this same content.f_text table via
     * Support\SchemaOrgEvent (Eventbrite/Humanitix), and Routing\
     * LinkObserver's raw_url cap (LinkObserver.php:50). Shared by every
     * connector's yield-time cap and every projector's belt-and-braces
     * re-cap so those bounds cannot drift into two different numbers.
     */
    public const MAX_LENGTH = 2000;
}
