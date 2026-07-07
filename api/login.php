<?php
// api/login.php — Endpoint login
// Method: POST
// Body (JSON): { "email": "<email ATAU nama bisnis UMKM>", "password": "" }
// UMKM dapat login menggunakan email ATAU business_name.
// Response: { "success": true/false, "message": "...", "user": {...} }

require_once __DIR__ . '/config.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method tidak diizinkan.'], 405);
}

$body       = json_decode(file_get_contents('php://input'), true);
// Field "email" dipertahankan untuk kompatibilitas frontend; isinya boleh
// alamat email ATAU nama bisnis (khusus UMKM). Alias "identifier" juga diterima.
$identifier = trim($body['email'] ?? ($body['identifier'] ?? ''));
$password   = $body['password'] ?? '';

if ($identifier === '' || $password === '') {
    jsonResponse(['success' => false, 'message' => 'Email/Nama Bisnis dan password wajib diisi.'], 422);
}

$pdo = getDB();

// ── Rate limiting (anti brute force) ────────────────────────────────────
// Dicek PALING AWAL, sebelum query user/verifikasi password apa pun --
// supaya selama terkunci, tidak ada percobaan password (hash bcrypt) yang
// dikerjakan sama sekali, dan pesan yang dibalas selalu sama terlepas akun
// itu ada atau tidak.
$remainingLock = checkLoginRateLimit($pdo, $identifier);
if ($remainingLock !== null) {
    jsonResponse([
        'success' => false,
        'message' => "Terlalu banyak percobaan login gagal. Coba lagi dalam {$remainingLock} detik.",
        'retry_after' => $remainingLock,
    ], 429);
}

// Profil LENGKAP ikut diambil (bukan cuma identitas dasar) — response login
// inilah yang disimpan frontend ke sessionStorage.currentUser, dan modal
// profil ("Detail Bisnis", "Skill yang Dikuasai", "Minat & Bidang" di
// updateProfileUI) membacanya langsung dari sana. Kalau location/skills/
// interests tidak ikut dikembalikan di sini, bagian itu tampil kosong
// ("Belum ada data") setelah login walau datanya ada di database.
$stmt = $pdo->prepare(
    "SELECT id, name, email, password, role, account_status, business_name, business_category,
            bio, location, portfolio_url, `field`, whatsapp, instagram, linkedin, website,
            address, latitude, longitude, skills AS skills_text, interests AS interests_text
     FROM   users
     WHERE  email = :ident
        OR (role = 'umkm' AND business_name = :ident2)
     LIMIT  1"
);
$stmt->execute([':ident' => $identifier, ':ident2' => $identifier]);
$user = $stmt->fetch();

// Cek user tidak ditemukan
if (!$user) {
    recordFailedLogin($pdo, $identifier);
    jsonResponse(['success' => false, 'message' => 'Email/Nama Bisnis atau password salah!'], 401);
}

// Cek akun dibekukan (Suspended oleh admin) — bukan bagian dari tebak-tebakan
// password, jadi tidak dihitung sebagai percobaan gagal.
if ($user['account_status'] === 'Suspended') {
    jsonResponse(['success' => false, 'message' => 'Akun Anda Dibekukan.'], 403);
}

// Verifikasi password
// Mendukung dua format: password_hash (bcrypt) DAN plain text untuk seed lama
$passwordValid = false;
if (password_verify($password, $user['password'])) {
    $passwordValid = true;
} elseif ($user['password'] === $password) {
    // Password lama plain text — upgrade ke hash saat login
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([$newHash, $user['id']]);
    $passwordValid = true;
}

if (!$passwordValid) {
    recordFailedLogin($pdo, $identifier);
    jsonResponse(['success' => false, 'message' => 'Email/Nama Bisnis atau password salah!'], 401);
}

// Login berhasil -- reset hitungan percobaan gagal untuk pasangan ini.
clearLoginAttempts($pdo, $identifier);

// Hapus password dari response
unset($user['password']);

// Alias status agar frontend lama (yang membaca user.status) tetap bekerja
$user['status'] = $user['account_status'];

// Skills & interests: sumber utama tabel relasional, fallback kolom teks
// (pola yang sama persis dengan api/me.php & api/get_user_profile.php).
$stmt = $pdo->prepare('SELECT skill FROM user_skills WHERE user_id = ?');
$stmt->execute([$user['id']]);
$skills = array_column($stmt->fetchAll(), 'skill');

$stmt = $pdo->prepare('SELECT interest FROM user_interests WHERE user_id = ?');
$stmt->execute([$user['id']]);
$interests = array_column($stmt->fetchAll(), 'interest');

$splitText = static function (?string $text): array {
    if ($text === null || trim($text) === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $text))));
};
if (empty($skills))    { $skills    = $splitText($user['skills_text']); }
if (empty($interests)) { $interests = $splitText($user['interests_text']); }

$user['skills']    = $skills;
$user['interests'] = $interests;
unset($user['skills_text'], $user['interests_text']);

// Mulai session PHP asli — ini sekarang sumber kebenaran identitas user,
// bukan lagi actor_id/freelancer_id/dst yang dikirim client di request lain.
// session_regenerate_id (TANPA hapus sesi lama -- lihat catatan penting di
// bawah) supaya id sesi baru tidak bisa "diramal" dari sebelum login (proteksi
// fixation standar: klien selalu dapat id BARU dari respons ini, bukan
// meneruskan id lama).
//
// PENTING (2026-07-05, isolasi sesi per-tab): PARAMETER $delete_old_session
// SENGAJA `false` (bukan `true` seperti sebelumnya). Request login ini bisa
// saja membawa cookie PHPSESSID milik TAB LAIN yang masih aktif (krn cookie
// dibagi ke semua tab, sedangkan tab yang login di sini belum tentu punya
// X-Session-Id sendiri sebelum berhasil login) -- `session_regenerate_id(true)`
// akan MENGHAPUS FILE SESI milik id yang sedang "dipinjam" itu dari disk,
// yang berarti MENGHANCURKAN sesi tab lain yang masih sah & sedang dipakai!
// Ketahuan lewat tests/e2e_per_tab_isolation_test.php. Dengan `false`, sesi
// lama itu ditinggalkan begitu saja (dibersihkan otomatis nanti oleh GC
// session.gc_maxlifetime) -- proteksi fixation TETAP dapat karena klien yang
// baru login selalu menerima id BARU (session_id() di bawah), bukan
// meneruskan id lama.
session_regenerate_id(false);
$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['role']    = $user['role'];

// session_id sekarang IKUT dikembalikan ke frontend supaya bisa disimpan di
// sessionStorage (per-tab) dan dikirim balik lewat header X-Session-Id di
// setiap request berikutnya -- inilah yang membuat setiap TAB browser bisa
// login sebagai akun berbeda secara independen, alih-alih semua tab berbagi
// satu cookie sesi yang sama (lihat api/config.php).
jsonResponse([
    'success'    => true,
    'message'    => 'Login berhasil.',
    'user'       => $user,
    'session_id' => session_id(),
]);
