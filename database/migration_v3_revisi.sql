-- ============================================================
-- MIGRASI V3 — Revisi fitur krusial Bantul Creative
-- Jalankan pada database `bantul_creative` (idempoten di MariaDB 10.4+
-- berkat IF NOT EXISTS).
--
-- Menambahkan:
--   users    : skills (text), interests (text), field (varchar)
--   projects : submission_link, rating (1-5), review_text
--
-- Catatan: api/config.php::ensureSchemaV2() juga membuat kolom-kolom
-- ini secara otomatis (self-healing), jadi file ini opsional.
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `skills`    TEXT         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `interests` TEXT         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `field`     VARCHAR(150) DEFAULT NULL;

ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `submission_link` TEXT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `rating`          INT  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `review_text`     TEXT DEFAULT NULL;

-- Isi kolom teks skills/interests dari tabel relasional yang sudah ada
UPDATE `users` u
SET u.`skills` = (SELECT GROUP_CONCAT(s.`skill` SEPARATOR ', ')
                  FROM `user_skills` s WHERE s.`user_id` = u.`id`)
WHERE u.`skills` IS NULL;

UPDATE `users` u
SET u.`interests` = (SELECT GROUP_CONCAT(i.`interest` SEPARATOR ', ')
                     FROM `user_interests` i WHERE i.`user_id` = u.`id`)
WHERE u.`interests` IS NULL;
