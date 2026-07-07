-- ============================================================
-- DATABASE: bantul_creative
-- Deskripsi: Database untuk platform Bantul Creative Village
--            Freelancer Trail
-- Versi: 1.0
-- Dibuat: 2026-07-02
-- ============================================================

CREATE DATABASE IF NOT EXISTS `bantul_creative`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `bantul_creative`;

-- ------------------------------------------------------------
-- TABEL: users
-- Menyimpan data akun pengguna yang mendaftar melalui form
-- Daftar Akun Baru (nama, email, role, password)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT(11)      NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(150) NOT NULL COMMENT 'Nama Lengkap',
  `email`            VARCHAR(200) NOT NULL COMMENT 'Alamat Email (unik)',
  `password`         VARCHAR(255) NOT NULL COMMENT 'Password (di-hash dengan password_hash PHP)',
  `role`             ENUM('freelancer','umkm','admin') NOT NULL DEFAULT 'freelancer'
                     COMMENT 'Daftar Sebagai: Freelancer / UMKM / Admin',
  `status`           ENUM('active','suspended') NOT NULL DEFAULT 'active',

  -- Data profil tambahan (diisi setelah registrasi)
  `bio`              TEXT         DEFAULT NULL COMMENT 'Deskripsi singkat pengguna',
  `location`         VARCHAR(200) DEFAULT NULL COMMENT 'Lokasi / kota',
  `portfolio_url`    VARCHAR(500) DEFAULT NULL COMMENT 'URL portfolio',
  `business_category` VARCHAR(150) DEFAULT NULL COMMENT 'Kategori bisnis (untuk UMKM)',
  `business_name`     VARCHAR(200) DEFAULT NULL COMMENT 'Nama bisnis/toko (untuk UMKM)',

  -- Kontak tambahan
  `whatsapp`         VARCHAR(30)  DEFAULT NULL,
  `instagram`        VARCHAR(100) DEFAULT NULL,
  `linkedin`         VARCHAR(200) DEFAULT NULL,
  `website`          VARCHAR(500) DEFAULT NULL,
  `address`          TEXT         DEFAULT NULL,

  -- Timestamps
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Akun pengguna platform Bantul Creative Village';

-- ------------------------------------------------------------
-- TABEL: user_skills
-- Relasi many-to-many: satu user bisa memiliki banyak skill
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_skills` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`   INT(11)      NOT NULL,
  `skill`     VARCHAR(100) NOT NULL COMMENT 'Nama skill (mis. Desain Grafis)',
  PRIMARY KEY (`id`),
  KEY `fk_skill_user` (`user_id`),
  CONSTRAINT `fk_skill_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABEL: user_interests
-- Minat/kategori yang diminati pengguna
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_interests` (
  `id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`   INT(11)      NOT NULL,
  `interest`  VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_interest_user` (`user_id`),
  CONSTRAINT `fk_interest_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABEL: projects
-- Proyek yang diposting oleh UMKM
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projects` (
  `id`            INT(11)       NOT NULL AUTO_INCREMENT,
  `created_by`    INT(11)       DEFAULT NULL COMMENT 'user_id UMKM pembuat proyek',
  `title`         VARCHAR(255)  NOT NULL,
  `description`   TEXT          DEFAULT NULL,
  `budget`        DECIMAL(15,2) DEFAULT 0.00,
  `deadline`      DATE          DEFAULT NULL,
  `status`        ENUM('Open','In Review','In Progress','Done','Closed')
                  NOT NULL DEFAULT 'Open',
  `icon`          VARCHAR(50)   DEFAULT 'puzzle',
  `assigned_to`   INT(11)       DEFAULT NULL COMMENT 'user_id Freelancer yang dipilih',
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_project_creator` (`created_by`),
  KEY `fk_project_assignee` (`assigned_to`),
  CONSTRAINT `fk_project_creator`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `fk_project_assignee`
    FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABEL: project_categories
-- Kategori proyek (bisa lebih dari satu per proyek)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `project_categories` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `project_id`  INT(11)      NOT NULL,
  `category`    VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_cat_project` (`project_id`),
  CONSTRAINT `fk_cat_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABEL: project_applicants
-- Freelancer yang melamar suatu proyek
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `project_applicants` (
  `id`            INT(11)  NOT NULL AUTO_INCREMENT,
  `project_id`    INT(11)  NOT NULL,
  `freelancer_id` INT(11)  NOT NULL,
  `status`        ENUM('Pending','Diterima','Ditolak') NOT NULL DEFAULT 'Pending'
                  COMMENT 'Status lamaran: menunggu / diterima / ditolak UMKM',
  `applied_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_application` (`project_id`, `freelancer_id`),
  KEY `fk_app_project` (`project_id`),
  KEY `fk_app_freelancer` (`freelancer_id`),
  CONSTRAINT `fk_app_project`
    FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_app_freelancer`
    FOREIGN KEY (`freelancer_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- TABEL: portfolio
-- Portofolio karya freelancer
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `portfolio` (
  `id`          INT(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     INT(11)      NOT NULL,
  `title`       VARCHAR(255) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `image_url`   VARCHAR(500) DEFAULT NULL,
  `project_url` VARCHAR(500) DEFAULT NULL,
  `tags`        VARCHAR(500) DEFAULT NULL COMMENT 'Comma-separated tags',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_portfolio_user` (`user_id`),
  CONSTRAINT `fk_portfolio_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- DATA AWAL (Seed) — Akun default untuk testing
-- Password menggunakan PHP password_hash() dengan PASSWORD_DEFAULT
-- Untuk generate hash baru, gunakan: api/generate_hash.php
-- Plain text password:
--   admin123  → role admin
--   user123   → role freelancer
--   umkm123   → role umkm
-- ------------------------------------------------------------
INSERT INTO `users`
  (`name`, `email`, `password`, `role`, `status`, `created_at`)
VALUES
  ('Admin System',
   'admin123@gmail.com',
   '$2y$10$Yesg7ZX623JvOkxhxxkRcu7H7iLL2XnMAJSBJ36mC0KRA9/Y8gQPC',
   'admin', 'active', NOW()),

  ('User Reguler',
   'user123@gmail.com',
   '$2y$10$naBpWgcszhePvT.UM5mG4.cfvBP5sD/z6nncozCFe5BQ3v/177HwC',
   'freelancer', 'active', NOW()),

  ('UMKM Kasongan',
   'umkm123@gmail.com',
   '$2y$10$937o459z58qpmANBH5zYJuYEqVpvmh02PfttqnVENCIaeWE.sVyrm',
   'umkm', 'active', NOW());

-- ============================================================
-- SELESAI
-- Cara import:
--   1. Buka phpMyAdmin → tab Import → pilih file ini → klik Go
--   2. Atau via CLI: mysql -u root -p < database/bantul_creative.sql
-- ============================================================
