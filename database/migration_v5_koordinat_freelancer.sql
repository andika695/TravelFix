-- ============================================================
-- MIGRASI V5 — Koordinat lokasi freelancer (AI Proximity & Skill Matcher)
-- Jalankan pada database `bantul_creative` (idempoten di MariaDB 10.4+
-- berkat IF NOT EXISTS).
--
-- Menambahkan:
--   users : latitude (DECIMAL 10,8), longitude (DECIMAL 11,8)
--
-- Catatan: api/config.php::ensureSchemaV2() juga membuat kolom-kolom
-- ini secara otomatis (self-healing), jadi file ini opsional.
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `latitude`  DECIMAL(10,8) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(11,8) DEFAULT NULL;
