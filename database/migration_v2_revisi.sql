-- ============================================================
-- MIGRATION V2 — Revisi Lanjutan Bantul Creative
-- Tanggal: 2026-07-03
--
-- Isi:
--   1. users    : + account_status ENUM('Active','Suspended'),
--                 + business_name (jika belum ada)
--   2. projects : + project_status ENUM('Open','In Progress',
--                   'Submitted','Completed','Closed')  ← menggantikan
--                   sistem status lama,
--                 + freelancer_id (freelancer yang mengerjakan)
--   3. chats    : tabel baru untuk pesan UMKM ↔ Freelancer
--
-- Cara import:
--   phpMyAdmin → pilih database bantul_creative → Import → file ini
--   atau CLI:  mysql -u root bantul_creative < database/migration_v2_revisi.sql
--
-- CATATAN: Sintaks "IF NOT EXISTS" pada ADD COLUMN membutuhkan
-- MariaDB (default XAMPP). Migration ini idempoten — aman dijalankan
-- berulang kali. api/config.php juga memiliki self-healing schema,
-- jadi kolom akan dibuat otomatis saat API pertama kali diakses
-- meskipun file ini belum diimport.
-- ============================================================

USE `bantul_creative`;

-- ------------------------------------------------------------
-- 1. TABEL users
-- ------------------------------------------------------------
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `account_status` ENUM('Active','Suspended') NOT NULL DEFAULT 'Active'
    COMMENT 'Status akun: Active / Suspended (dibekukan admin)' AFTER `role`,
  ADD COLUMN IF NOT EXISTS `business_name` VARCHAR(200) DEFAULT NULL
    COMMENT 'Nama bisnis/toko (untuk UMKM, bisa dipakai login)' AFTER `business_category`;

-- Salin nilai dari kolom status lama ('active'/'suspended')
UPDATE `users`
SET `account_status` = IF(`status` = 'suspended', 'Suspended', 'Active');

-- ------------------------------------------------------------
-- 2. TABEL projects
-- ------------------------------------------------------------
ALTER TABLE `projects`
  ADD COLUMN IF NOT EXISTS `location` VARCHAR(200) DEFAULT NULL AFTER `deadline`,
  ADD COLUMN IF NOT EXISTS `requirements` TEXT DEFAULT NULL AFTER `location`,
  ADD COLUMN IF NOT EXISTS `project_status`
    ENUM('Open','In Progress','Submitted','Completed','Closed') NOT NULL DEFAULT 'Open'
    COMMENT 'Status proyek (sistem baru, menggantikan kolom status)' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `freelancer_id` INT(11) DEFAULT NULL
    COMMENT 'user_id freelancer yang sedang mengerjakan proyek' AFTER `assigned_to`,
  ADD KEY IF NOT EXISTS `idx_project_freelancer` (`freelancer_id`);

-- Mapping status lama → status baru
--   Open / In Review → Open, In Progress → In Progress,
--   Done → Completed, Closed → Closed
UPDATE `projects`
SET `project_status` = CASE `status`
  WHEN 'In Progress' THEN 'In Progress'
  WHEN 'Done'        THEN 'Completed'
  WHEN 'Closed'      THEN 'Closed'
  ELSE 'Open'
END;

-- Isi freelancer_id dari assigned_to lama
UPDATE `projects`
SET `freelancer_id` = `assigned_to`
WHERE `assigned_to` IS NOT NULL AND `freelancer_id` IS NULL;

-- Isi freelancer_id dari pelamar yang sudah Diterima (jika assigned_to kosong)
UPDATE `projects` p
JOIN `project_applicants` pa
  ON pa.`project_id` = p.`id` AND pa.`status` = 'Diterima'
SET p.`freelancer_id` = pa.`freelancer_id`
WHERE p.`freelancer_id` IS NULL;

-- ------------------------------------------------------------
-- 3. TABEL chats — pesan antara UMKM dan Freelancer per proyek
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chats` (
  `id`          INT(11)  NOT NULL AUTO_INCREMENT,
  `project_id`  INT(11)  NOT NULL,
  `sender_id`   INT(11)  NOT NULL COMMENT 'user_id pengirim',
  `receiver_id` INT(11)  NOT NULL COMMENT 'user_id penerima',
  `message`     TEXT     NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_project` (`project_id`, `created_at`),
  KEY `idx_chat_receiver` (`receiver_id`),
  KEY `idx_chat_sender` (`sender_id`),
  CONSTRAINT `fk_chat_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_sender`
    FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_receiver`
    FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Pesan proyek antara UMKM dan Freelancer';

-- ------------------------------------------------------------
-- OPSIONAL (jalankan nanti setelah semua berjalan stabil):
-- kolom lama sudah tidak dipakai kode dan boleh dihapus.
-- ------------------------------------------------------------
-- ALTER TABLE `projects` DROP COLUMN `status`, DROP COLUMN `assigned_to`;
-- ALTER TABLE `users`    DROP COLUMN `status`;

-- ============================================================
-- SELESAI
-- ============================================================
