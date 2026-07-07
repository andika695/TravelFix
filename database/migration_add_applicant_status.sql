-- ============================================================
-- MIGRASI: Tambah kolom `status` ke tabel `project_applicants`
-- Jalankan sekali saja untuk database yang sudah ada (dibuat sebelum
-- kolom ini ditambahkan ke database/bantul_creative.sql).
--
-- Cara import:
--   1. Buka phpMyAdmin → pilih database bantul_creative → tab SQL → tempel isi file ini → Go
--   2. Atau via CLI: mysql -u root -p bantul_creative < database/migration_add_applicant_status.sql
-- ============================================================

USE `bantul_creative`;

ALTER TABLE `project_applicants`
  ADD COLUMN `status` ENUM('Pending','Diterima','Ditolak') NOT NULL DEFAULT 'Pending'
  COMMENT 'Status lamaran: menunggu / diterima / ditolak UMKM'
  AFTER `freelancer_id`;
