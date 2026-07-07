<?php
// api/config.php — Konfigurasi koneksi database MySQL
// Sesuaikan nilai berikut dengan pengaturan XAMPP Anda

// ── Session PHP asli (sumber kebenaran identitas pengguna) ──────────────
// Sebelumnya aplikasi ini 100% percaya actor_id/freelancer_id/umkm_id yang
// dikirim client tanpa verifikasi apa pun. Sekarang login.php menaruh
// identitas ke $_SESSION, dan endpoint mutasi WAJIB pakai currentSessionUserId()
// (lihat di bawah) -- bukan lagi field dari body/query request.
//
// ── Isolasi sesi PER TAB (2026-07-05) ────────────────────────────────────
// Masalah: cookie PHPSESSID dibagi ke SEMUA tab pada satu browser (cookie
// scope-nya per-origin, bukan per-tab) -- kalau tab A login sebagai
// freelancer lalu tab B (browser sama) login sebagai UMKM, session PHP
// BROWSER itu (bukan cuma tab B) ikut berpindah ke UMKM, sehingga tab A
// yang masih terbuka tiba-tiba "menjadi" UMKM juga di mata server. Ini akar
// dari serangkaian bug "sesi bentrok" (Hanya akun freelancer.../Akses
// ditolak/bukan partisipan chat) yang dilaporkan berulang.
// Solusi: frontend (js/db.js, lihat wrapper fetch()) mengirim id sesi
// SECARA EKSPLISIT lewat header `X-Session-Id`, disimpan di sessionStorage
// (yang MEMANG ter-isolasi per tab, tidak seperti cookie) sejak login.
// Kalau header ini ada & formatnya valid, PAKSA PHP memakai sesi itu
// (session_id() sebelum session_start()) alih-alih cookie bersama --
// setiap tab pun akhirnya benar-benar independen. Kalau header tidak ada
// (mis. panggilan lama/tes lewat cookie jar biasa), fallback ke cookie
// seperti sebelumnya -- tidak ada yang rusak untuk klien yang belum kirim
// header ini.
//
// Catatan keamanan: id sesi kini juga dibaca lewat header yang bisa diakses
// JS (makanya perlu disimpan di sessionStorage, bukan cookie httponly) --
// ini melemahkan mitigasi XSS yang sebelumnya didapat dari flag `httponly`
// (skrip jahat hasil XSS kini bisa membaca id sesi dari sessionStorage).
// Trade-off ini disengaja demi isolasi per-tab yang diminta; risikonya
// setara dengan aplikasi SPA token-based pada umumnya (Bearer token di
// localStorage/sessionStorage) -- tetap WAJIB validasi format ketat di
// bawah supaya header ini tidak bisa dipakai menebak/menyuntik id sesi.
if (session_status() === PHP_SESSION_NONE) {
    $explicitSessionId = $_SERVER['HTTP_X_SESSION_ID'] ?? '';
    // Format id sesi PHP baku: alfanumerik + koma/strip, ~22-52 karakter
    // (tergantung session.sid_length & session.sid_bits_per_character).
    // Validasi ketat mencegah header ini dipakai menyuntik path/karakter aneh.
    if ($explicitSessionId !== '' && preg_match('/^[a-zA-Z0-9,-]{22,52}$/', $explicitSessionId)) {
        session_id($explicitSessionId);
    }

    session_set_cookie_params([
        'httponly' => true,  // cookie sesi tidak bisa dibaca lewat JS (mitigasi dampak XSS)
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Baca file .env (kalau ada) ───────────────────────────────────────────
// Proyek ini murni PHP tanpa composer/vendor, jadi getenv() TIDAK otomatis
// membaca file .env seperti di framework lain -- perlu di-parse manual lalu
// dimasukkan ke environment lewat putenv(). Kalau .env tidak ada sama sekali
// (mis. belum dibuat), baris ini di-skip diam-diam dan fallback default di
// bawah (localhost/root/tanpa password, cocok utk XAMPP) yang dipakai.
function loadEnvFile(string $path): void {
    if (!is_readable($path)) return;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Lepas tanda kutip pembungkus kalau ada ("nilai" atau 'nilai')
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = substr($value, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Jangan timpa environment variable yang memang sudah di-set duluan
        // (mis. lewat Docker/OS) -- .env cuma pelengkap, bukan pemaksa.
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}
loadEnvFile(__DIR__ . '/../.env');

// Nilai default = XAMPP lokal (tidak berubah kalau .env/Docker tidak ada).
// Kalau environment variable ini di-set (dari .env di atas, atau lewat
// docker-compose.yml), nilainya dipakai duluan -- supaya file ini sama
// persis dipakai di XAMPP polos, Docker, maupun dengan .env manual.
define('DB_HOST',     getenv('DB_HOST')     ?: 'localhost');
define('DB_PORT',     getenv('DB_PORT')     ?: '3306');
define('DB_NAME',     getenv('DB_NAME')     ?: 'bantul_creative');
define('DB_USER',     getenv('DB_USER')     ?: 'root');
define('DB_PASS',     getenv('DB_PASS')     ?: '');
define('DB_CHARSET',  getenv('DB_CHARSET')  ?: 'utf8mb4');

/**
 * Membuat koneksi PDO ke MySQL.
 * Melempar exception jika gagal konek.
 *
 * @return PDO
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Koneksi database gagal: ' . $e->getMessage()
            ]);
            exit;
        }

        ensureSchemaV2($pdo);
    }

    return $pdo;
}

/**
 * Self-healing schema V2 — memastikan kolom/tabel revisi terbaru ada,
 * meskipun database/migration_v2_revisi.sql belum diimport.
 * Idempoten: hanya ALTER saat kolom benar-benar belum ada.
 */
function ensureSchemaV2(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        // users.account_status (Active/Suspended) — sistem baru
        if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'account_status'")->fetch()) {
            $pdo->exec(
                "ALTER TABLE `users`
                 ADD COLUMN `account_status` ENUM('Active','Suspended') NOT NULL DEFAULT 'Active' AFTER `role`"
            );
            // Salin nilai dari kolom status lama jika ada
            if ($pdo->query("SHOW COLUMNS FROM `users` LIKE 'status'")->fetch()) {
                $pdo->exec("UPDATE `users` SET `account_status` = IF(`status`='suspended','Suspended','Active')");
            }
        }

        // users.business_name (untuk UMKM)
        if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'business_name'")->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `business_name` VARCHAR(200) DEFAULT NULL");
        }

        // V3: users.skills / interests (teks, sinkron dari tabel relasional) + field (bidang)
        if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'skills'")->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `skills` TEXT DEFAULT NULL");
            $pdo->exec(
                "UPDATE `users` u SET u.`skills` =
                   (SELECT GROUP_CONCAT(s.`skill` SEPARATOR ', ') FROM `user_skills` s WHERE s.`user_id` = u.`id`)"
            );
        }
        if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'interests'")->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `interests` TEXT DEFAULT NULL");
            $pdo->exec(
                "UPDATE `users` u SET u.`interests` =
                   (SELECT GROUP_CONCAT(i.`interest` SEPARATOR ', ') FROM `user_interests` i WHERE i.`user_id` = u.`id`)"
            );
        }
        if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'field'")->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `field` VARCHAR(150) DEFAULT NULL");
        }

        // V5: users.latitude/longitude — koordinat dari Map Picker di Profil
        // Saya (freelancer), dipakai fitur AI Proximity & Skill Matcher untuk
        // menghitung jarak ke proyek (rumus Haversine).
        if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'latitude'")->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `latitude` DECIMAL(10,8) DEFAULT NULL");
        }
        if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'longitude'")->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `longitude` DECIMAL(11,8) DEFAULT NULL");
        }

        // V6: users.public_email — "Email Publik (Kontak)" di form Profil,
        // SENGAJA kolom terpisah dari users.email (dipakai login/otentikasi,
        // lihat "Email Login" readonly di profile.html). Sebelumnya field ini
        // ada di form tapi TIDAK PERNAH tersimpan (mapUserUpdatesToApi() sengaja
        // membuangnya karena kolomnya belum ada) -- perubahan yang diketik user
        // selalu diam-diam hilang. Backfill dari users.email saat kolom dibuat
        // supaya akun lama tidak mendadak tampak "belum lengkap" untuk field
        // yang secara de-facto sudah terisi (form ini selalu prefill dari email
        // login sejak awal).
        if (!$pdo->query("SHOW COLUMNS FROM `users` LIKE 'public_email'")->fetch()) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `public_email` VARCHAR(200) DEFAULT NULL AFTER `email`");
            $pdo->exec("UPDATE `users` SET `public_email` = `email` WHERE `public_email` IS NULL");
        }

        // projects.location & requirements (dipakai form Buat Proyek)
        if (!$pdo->query("SHOW COLUMNS FROM `projects` LIKE 'location'")->fetch()) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `location` VARCHAR(200) DEFAULT NULL AFTER `deadline`");
        }
        if (!$pdo->query("SHOW COLUMNS FROM `projects` LIKE 'requirements'")->fetch()) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `requirements` TEXT DEFAULT NULL AFTER `location`");
        }

        // projects.project_status — sistem status baru
        if (!$pdo->query("SHOW COLUMNS FROM `projects` LIKE 'project_status'")->fetch()) {
            $pdo->exec(
                "ALTER TABLE `projects`
                 ADD COLUMN `project_status` ENUM('Open','In Progress','Submitted','Completed','Closed')
                 NOT NULL DEFAULT 'Open'"
            );
            if ($pdo->query("SHOW COLUMNS FROM `projects` LIKE 'status'")->fetch()) {
                $pdo->exec(
                    "UPDATE `projects` SET `project_status` = CASE `status`
                       WHEN 'In Progress' THEN 'In Progress'
                       WHEN 'Done'        THEN 'Completed'
                       WHEN 'Closed'      THEN 'Closed'
                       ELSE 'Open' END"
                );
            }
        }

        // projects.freelancer_id — freelancer yang mengerjakan
        if (!$pdo->query("SHOW COLUMNS FROM `projects` LIKE 'freelancer_id'")->fetch()) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `freelancer_id` INT(11) DEFAULT NULL, ADD KEY `idx_project_freelancer` (`freelancer_id`)");
            if ($pdo->query("SHOW COLUMNS FROM `projects` LIKE 'assigned_to'")->fetch()) {
                $pdo->exec("UPDATE `projects` SET `freelancer_id` = `assigned_to` WHERE `assigned_to` IS NOT NULL");
            }
            $pdo->exec(
                "UPDATE `projects` p
                 JOIN `project_applicants` pa ON pa.`project_id` = p.`id` AND pa.`status` = 'Diterima'
                 SET p.`freelancer_id` = pa.`freelancer_id`
                 WHERE p.`freelancer_id` IS NULL"
            );
        }

        // V3: projects.submission_link + rating + review_text (alur submit & review)
        if (!$pdo->query("SHOW COLUMNS FROM `projects` LIKE 'submission_link'")->fetch()) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `submission_link` TEXT DEFAULT NULL");
        }
        if (!$pdo->query("SHOW COLUMNS FROM `projects` LIKE 'rating'")->fetch()) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `rating` INT DEFAULT NULL");
        }
        if (!$pdo->query("SHOW COLUMNS FROM `projects` LIKE 'review_text'")->fetch()) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `review_text` TEXT DEFAULT NULL");
        }

        // V4: projects.latitude/longitude — koordinat dari Map Picker (UMKM),
        // dipakai untuk merender marker proyek otomatis di Creative Trail Map.
        if (!$pdo->query("SHOW COLUMNS FROM `projects` LIKE 'latitude'")->fetch()) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `latitude` DECIMAL(10,8) DEFAULT NULL");
        }
        if (!$pdo->query("SHOW COLUMNS FROM `projects` LIKE 'longitude'")->fetch()) {
            $pdo->exec("ALTER TABLE `projects` ADD COLUMN `longitude` DECIMAL(11,8) DEFAULT NULL");
        }

        // Tabel chats
        if (!$pdo->query("SHOW TABLES LIKE 'chats'")->fetch()) {
            $pdo->exec(
                "CREATE TABLE `chats` (
                   `id`          INT(11)  NOT NULL AUTO_INCREMENT,
                   `project_id`  INT(11)  NOT NULL,
                   `sender_id`   INT(11)  NOT NULL,
                   `receiver_id` INT(11)  NOT NULL,
                   `message`     TEXT     NOT NULL,
                   `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   PRIMARY KEY (`id`),
                   KEY `idx_chat_project` (`project_id`, `created_at`),
                   KEY `idx_chat_receiver` (`receiver_id`),
                   KEY `idx_chat_sender` (`sender_id`),
                   CONSTRAINT `fk_chat_project`  FOREIGN KEY (`project_id`)  REFERENCES `projects` (`id`) ON DELETE CASCADE,
                   CONSTRAINT `fk_chat_sender`   FOREIGN KEY (`sender_id`)   REFERENCES `users` (`id`)    ON DELETE CASCADE,
                   CONSTRAINT `fk_chat_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`)    ON DELETE CASCADE
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        // Tabel login_attempts — rate limiting brute force di login.php
        if (!$pdo->query("SHOW TABLES LIKE 'login_attempts'")->fetch()) {
            $pdo->exec(
                "CREATE TABLE `login_attempts` (
                   `id`              INT(11)  NOT NULL AUTO_INCREMENT,
                   `identifier`      VARCHAR(200) NOT NULL,
                   `ip_address`      VARCHAR(45)  NOT NULL,
                   `attempt_count`   INT(11)  NOT NULL DEFAULT 0,
                   `last_attempt_at` DATETIME NOT NULL,
                   `locked_until`    DATETIME NULL DEFAULT NULL,
                   PRIMARY KEY (`id`),
                   UNIQUE KEY `uniq_identifier_ip` (`identifier`, `ip_address`)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    } catch (PDOException $e) {
        // Jangan blokir request hanya karena self-healing gagal;
        // migrasi manual via database/migration_v2_revisi.sql tetap tersedia.
        error_log('[ensureSchemaV2] ' . $e->getMessage());
    }
}

/**
 * ID user yang sedang login menurut session PHP (bukan dari body/query
 * request client). Ini sumber kebenaran identitas satu-satunya sekarang.
 */
function currentSessionUserId(): ?int {
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

/**
 * Wajib sudah login (session valid) untuk lanjut. Menghentikan request
 * dengan 401 jika belum login sama sekali, atau user di session ternyata
 * sudah tidak ada/berbeda di DB (mis. dihapus admin).
 *
 * @return array Baris user yang sedang login (id, role, dst).
 */
function requireAuth(PDO $pdo): array {
    $userId = currentSessionUserId();
    if (!$userId) {
        jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Anda harus login untuk melakukan aksi ini.'], 401);
    }
    $stmt = $pdo->prepare('SELECT id, role, account_status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        session_unset();
        session_destroy();
        jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Sesi tidak valid. Silakan login kembali.'], 401);
    }
    if (($user['account_status'] ?? 'Active') === 'Suspended') {
        jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Akun Anda Dibekukan.'], 403);
    }
    return $user;
}

/**
 * Verifikasi bahwa actorId adalah pengguna nyata dengan salah satu role yang
 * diizinkan (mis. ['admin']). Menghentikan request dengan 403 jika tidak lolos.
 * Dipakai untuk aksi mutasi (suspend/hapus/tutup paksa) yang hanya boleh
 * dilakukan admin — endpoint ini sebelumnya tidak diverifikasi sama sekali.
 *
 * @return array Baris user actor (id, role) jika lolos.
 */
function requireRole(PDO $pdo, ?int $actorId, array $allowedRoles): array {
    if (!$actorId) {
        jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Akses ditolak. Anda harus login untuk melakukan aksi ini.'], 403);
    }
    $stmt = $pdo->prepare('SELECT id, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$actorId]);
    $actor = $stmt->fetch();
    if (!$actor || !in_array($actor['role'], $allowedRoles, true)) {
        jsonResponse(['success' => false, 'status' => 'error', 'message' => 'Akses ditolak. Anda tidak memiliki izin untuk melakukan aksi ini.'], 403);
    }
    return $actor;
}

// ── Rate limiting login (anti brute force) ──────────────────────────────
// Dikunci per pasangan (identifier, ip_address) -- bukan cuma identifier
// sendirian, supaya orang lain yang login sah dari IP berbeda ke akun yang
// sama tidak ikut ter-blokir gara-gara ada pihak lain yang menebak-nebak
// password akun tersebut dari IP lain.
const LOGIN_MAX_ATTEMPTS   = 3;   // percobaan gagal beruntun yang diizinkan
const LOGIN_LOCKOUT_SECONDS = 30; // durasi wajib menunggu setelah melewati batas

function loginRateLimitKey(string $identifier): string {
    return mb_strtolower(trim($identifier));
}

function clientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Cek apakah (identifier, ip) sedang dalam masa kunci. Mengembalikan jumlah
 * detik sisa tunggu jika masih terkunci, atau null jika boleh lanjut mencoba.
 */
function checkLoginRateLimit(PDO $pdo, string $identifier): ?int {
    $key = loginRateLimitKey($identifier);
    $ip  = clientIp();

    // Bersih-bersih ringan: baris lama (>1 hari, tidak sedang terkunci) dibuang
    // supaya tabel ini tidak tumbuh tanpa batas.
    $pdo->exec("DELETE FROM login_attempts WHERE last_attempt_at < (NOW() - INTERVAL 1 DAY) AND (locked_until IS NULL OR locked_until < NOW())");

    $stmt = $pdo->prepare('SELECT locked_until FROM login_attempts WHERE identifier = ? AND ip_address = ? LIMIT 1');
    $stmt->execute([$key, $ip]);
    $row = $stmt->fetch();

    if ($row && $row['locked_until'] !== null) {
        $remaining = strtotime($row['locked_until']) - time();
        if ($remaining > 0) {
            return $remaining;
        }
    }
    return null;
}

/**
 * Catat satu percobaan login GAGAL. Setelah mencapai LOGIN_MAX_ATTEMPTS,
 * (identifier, ip) dikunci selama LOGIN_LOCKOUT_SECONDS -- percobaan gagal
 * berikutnya (termasuk setelah masa kunci habis) memperpanjang kunci lagi.
 */
function recordFailedLogin(PDO $pdo, string $identifier): void {
    $key = loginRateLimitKey($identifier);
    $ip  = clientIp();

    $stmt = $pdo->prepare('SELECT attempt_count FROM login_attempts WHERE identifier = ? AND ip_address = ? LIMIT 1');
    $stmt->execute([$key, $ip]);
    $row = $stmt->fetch();

    $newCount = ($row ? (int) $row['attempt_count'] : 0) + 1;
    $lockedUntil = $newCount >= LOGIN_MAX_ATTEMPTS
        ? date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_SECONDS)
        : null;

    $pdo->prepare(
        'INSERT INTO login_attempts (identifier, ip_address, attempt_count, last_attempt_at, locked_until)
         VALUES (?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE attempt_count = ?, last_attempt_at = NOW(), locked_until = ?'
    )->execute([$key, $ip, $newCount, $lockedUntil, $newCount, $lockedUntil]);
}

/** Hapus catatan percobaan gagal setelah login berhasil. */
function clearLoginAttempts(PDO $pdo, string $identifier): void {
    $key = loginRateLimitKey($identifier);
    $ip  = clientIp();
    $pdo->prepare('DELETE FROM login_attempts WHERE identifier = ? AND ip_address = ?')->execute([$key, $ip]);
}

/**
 * Helper: Kirim JSON response dan keluar.
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Helper: Set CORS headers untuk request dari browser.
 * Sesuaikan origin jika deploy ke production.
 */
function setCorsHeaders(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Id');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
