-- Content selection: add the 'ig-photo' entry type — an individual Instagram
-- post image picked from the library (referenced by its R2-mirrored URL),
-- distinct from the ig-post/ig-reel AUTO slots which carry no reference.
-- external_ref holds the image URL, mirroring the google-photo shape.

ALTER TABLE "site"."content_selection"
    DROP CONSTRAINT "content_selection_entry_type_check";

ALTER TABLE "site"."content_selection"
    ADD CONSTRAINT "content_selection_entry_type_check"
    CHECK (("entry_type" = ANY (ARRAY['upload'::"text", 'google-photo'::"text", 'ig-reel'::"text", 'ig-post'::"text", 'ig-photo'::"text"])));

ALTER TABLE "site"."content_selection"
    DROP CONSTRAINT "content_selection_ref_shape";

ALTER TABLE "site"."content_selection"
    ADD CONSTRAINT "content_selection_ref_shape"
    CHECK (
        (("entry_type" = 'upload'::"text") AND ("media_id" IS NOT NULL) AND ("external_ref" IS NULL))
        OR (("entry_type" = ANY (ARRAY['google-photo'::"text", 'ig-photo'::"text"])) AND ("external_ref" IS NOT NULL) AND ("media_id" IS NULL))
        OR (("entry_type" = ANY (ARRAY['ig-reel'::"text", 'ig-post'::"text"])) AND ("media_id" IS NULL) AND ("external_ref" IS NULL))
    );
