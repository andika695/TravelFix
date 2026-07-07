-- ============================================================
-- MIGRASI V4 — Koordinat lokasi proyek (Map Picker <-> Trail Map)
-- Jalankan pada database `bantul_creative` (idempoten di MariaDB 10.4+
-- berkat IF NOT EXISTS).
--
-- Menambahkan:
--   projects : latitude (DECIMAL 10,8), longitude (DECIMAL 11,8)
--
-- Catatan: api/config.php::ensureSchemaV2() juga membuat kolom-kolom
-- ini secara otomatis (self-healing), jadi file ini opsional.
-- ============================================================

ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `latitude`  DECIMAL(10,8) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(11,8) DEFAULT NULL;
