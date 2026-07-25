-- weight.light — Inferred kit var (base − 100, dispatcher-emitted; nullable
-- column kept for promotion like the other inferred weights).
-- effects.scrimBlur — Value kit var: backdrop blur on glass scrims (card
-- label strips, hero overlays). Package default: 6px.
ALTER TABLE site.design_kits ADD COLUMN IF NOT EXISTS weight_light TEXT NULL;
ALTER TABLE site.design_kits ADD COLUMN IF NOT EXISTS effect_scrim_blur TEXT NULL;
