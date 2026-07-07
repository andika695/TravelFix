-- ============================================================
-- MIGRASI V6 — Email Publik (Kontak) terpisah dari email login
-- Jalankan pada database `bantul_creative` (idempoten di MariaDB 10.4+
-- berkat IF NOT EXISTS).
--
-- Menambahkan:
--   users : public_email (VARCHAR 200) — "Email Publik (Kontak)" di form
--           Profil, terpisah dari users.email (dipakai login/otentikasi).
--
-- Backfill: akun lama diisi public_email = email supaya tidak mendadak
-- tampak "belum lengkap" untuk field yang secara de-facto sudah terisi
-- (form Profil selalu prefill dari email login sejak awal).
--
-- Catatan: api/config.php::ensureSchemaV2() juga membuat kolom ini secara
-- otomatis (self-healing), jadi file ini opsional.
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `public_email` VARCHAR(200) DEFAULT NULL AFTER `email`;

UPDATE `users` SET `public_email` = `email` WHERE `public_email` IS NULL;
