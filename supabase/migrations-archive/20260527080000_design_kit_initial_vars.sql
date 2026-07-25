-- =====================================================================
-- First five design kit var columns
-- =====================================================================
-- Phase 7a of the skeleton-system rollout. Spec: §5.1 (categories table).
-- Adds the Colors + Typography groups' first vars to site.design_kits.
--
-- All columns NULLABLE, no DB-level DEFAULT — code-side defaults in
-- @partnaau/design-system/design-kit fill nulls at read time via
-- mergeDesignKit(). Storage convention: flat snake_case column names
-- (group prefix + key); the API maps to nested camelCase
-- (color_accent → designKit.colors.accent).
--
-- Plan: docs/superpowers/plans/2026-05-26-skeleton-system.md Phase 7.
-- =====================================================================

ALTER TABLE site.design_kits
  ADD COLUMN IF NOT EXISTS color_accent TEXT NULL,
  ADD COLUMN IF NOT EXISTS color_bg TEXT NULL,
  ADD COLUMN IF NOT EXISTS color_text TEXT NULL,
  ADD COLUMN IF NOT EXISTS typography_font_heading TEXT NULL,
  ADD COLUMN IF NOT EXISTS typography_font_body TEXT NULL;
