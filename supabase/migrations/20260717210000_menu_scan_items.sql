-- Automatic Google-photos menu scan (2026-07-17): the last successful scan's
-- extracted items, persisted so MenuFetchJob's wholesale scrape rebuilds can
-- RE-APPLY the enrichment (longer descriptions, dietary badges, scan-only
-- items) without re-running the paid OCR pipeline.
-- Shape: { items: [ {name, description, price, category, dietary} ... ],
--          source: 'google-photos', scannedAt: ISO8601 }
ALTER TABLE site.menus ADD COLUMN IF NOT EXISTS scan_items JSONB NULL;
