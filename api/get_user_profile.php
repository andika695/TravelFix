<?php
// api/get_user_profile.php — Detail profil lengkap seorang pengguna
// GET ?id=N  (alias: ?user_id=N)
//
// Dipakai oleh: modal profil di halaman Admin, dan validasi profil
// freelancer sebelum melamar proyek.
//
// Sukses → { "status": "success", "message": "...", "data": { ...user,
//            skills[], interests[], field, profile_complete } }
// Gagal  → { "status": "error", "message": "..." } (selalu JSON valid)

require_once __DIR__ . '/config.php';

setCorsHeaders();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'error', 'message' => 'Method tidak diizinkan.'], 405);
}

// Sanitasi: paksa menjadi integer agar aman dari SQL Injection
$userId = intval($_GET['id'] ?? ($_GET['user_id'] ?? 0));
if ($userId <= 0) {
    jsonResponse(['status' => 'error', 'message' => 'Parameter id wajib diisi dan berupa angka.'], 422);
}

try {
    $pdo = getDB();

    // Wajib login (siapa pun boleh, bukan cuma pemilik profil -- endpoint ini
    // dipakai UMKM melihat profil pelamar/talenta, dan freelancer melihat
    // profil UMKM pemilik proyek). Sebelumnya TANPA pengecekan sama sekali --
    // siapa pun tanpa login bisa mengambil WhatsApp, alamat, dan koordinat GPS
    // persis milik user mana pun hanya dengan menebak id-nya.
    requireAuth($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, name, email, public_email, role, account_status, bio, location, portfolio_url,
                business_name, business_category, `field`, skills AS skills_text,
                interests AS interests_text, whatsapp, instagram, linkedin, website,
                address, latitude, longitude, created_at, updated_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['status' => 'error', 'message' => 'Pengguna tidak ditemukan.'], 404);
    }

    // Skills & interests: sumber utama tabel relasional, fallback kolom teks
    $stmt = $pdo->prepare('SELECT skill FROM user_skills WHERE user_id = ?');
    $stmt->execute([$userId]);
    $skills = array_column($stmt->fetchAll(), 'skill');

    $stmt = $pdo->prepare('SELECT interest FROM user_interests WHERE user_id = ?');
    $stmt->execute([$userId]);
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

    // Penanda kelengkapan profil (dipakai validasi sebelum melamar proyek)
    $user['profile_complete'] = !empty($skills) && !empty($interests);

    jsonResponse([
        'status'  => 'success',
        'message' => 'Profil pengguna berhasil dimuat.',
        'data'    => $user,
    ]);

} catch (PDOException $e) {
    error_log(basename(__FILE__) . ': ' . $e->getMessage());
    jsonResponse(['status' => 'error', 'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.'], 500);
}
